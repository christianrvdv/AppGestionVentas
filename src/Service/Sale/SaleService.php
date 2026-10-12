<?php

declare(strict_types=1);

namespace App\Service\Sale;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\InventoryMovement;
use App\Entity\Sale;
use App\Entity\SaleLine;
use App\Exception\Domain\InsufficientStockException;
use App\Exception\Domain\InvestmentNotConfirmedException;
use App\Exception\Domain\InvestmentNotFoundException;
use App\Exception\Domain\ItemNotInInvestmentException;
use App\Exception\Domain\TenantMismatchException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Repository\Contract\InvestmentItemRepositoryInterface;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Repository\Contract\SaleLineRepositoryInterface;
use App\Service\ActiveTenantProvider;
use App\Service\Investment\InvestmentStateMachine;
use App\Service\Investment\InvestmentSummaryService;
use App\Service\Investment\RecoveryRecognitionService;
use App\Service\SettingsService;
use App\Service\UsdRateService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Servicio principal de ventas. ÚNICO PUNTO DE ENTRADA para registrar ventas.
 *
 * POLÍTICA DE FLUSH: este servicio hace flush() internamente.
 *   - Flush #1: tras persistir Sale + SaleLines → obtener IDs para idempotency keys.
 *   - Flush #2: tras persistir InventoryMovements → que el summary los vea.
 *   - Flush #3: al final, tras recompute + updateInvestmentState.
 *
 * [FIX-1] Stock validado en memoria durante el loop de líneas para evitar
 *         stock negativo cuando hay múltiples líneas del mismo ítem
 *         (los InventoryMovements no existen hasta después del flush).
 *
 * @phpstan-type SaleLineData array{
 *   line: SaleLine,
 *   item: InvestmentItem,
 *   quantity: int,
 *   unitCostSnapshot: string,
 *   currentCostSnapshot: string,
 *   costRecovered: string
 * }
 */
final class SaleService
{
    public function __construct(
        private readonly InvestmentItemRepositoryInterface    $itemRepository,
        private readonly CustomerRepositoryInterface          $customerRepository,
        private readonly InventoryMovementRepositoryInterface $movementRepository,
        private readonly SaleLineRepositoryInterface          $saleLineRepository,
        private readonly InvestmentSummaryRepositoryInterface $summaryRepository,
        private readonly InvestmentStateMachine               $stateMachine,
        private readonly InvestmentSummaryService             $summaryService,
        private readonly SettingsService                      $settingsService,
        private readonly UsdRateService                       $usdRateService,
        private readonly InvestmentRepositoryInterface        $investmentRepository,
        private readonly EntityManagerInterface               $entityManager,
        private readonly ActiveTenantProvider                 $activeTenantProvider,
        private readonly RecoveryRecognitionService           $recoveryRecognitionService
    )
    {
    }

    public function register(RegisterSaleCommand $cmd, AppUser $user): Sale
    {
        // 1. Obtener inversión (el tenant activo de la sesión, ADR-0015)
        $investment = $this->loadInvestment(
            $cmd->investmentId,
            $this->activeTenantProvider->requireTenant()->getId()
        );

        // 2. Validar inversión confirmada
        if (!$investment->isConfirmed()) {
            throw InvestmentNotConfirmedException::forInvestment($investment->getId());
        }

        // 3. Moneda
        $currency = $investment->getBaseCurrency();

        // 4. Cliente opcional
        $customer = null;
        if ($cmd->customerId !== null) {
            $customer = $this->customerRepository->findByIdAndTenant($cmd->customerId, $investment->getTenant()->getId());
            if ($customer === null) {
                throw new \LogicException(sprintf('Cliente %d no encontrado.', $cmd->customerId));
            }
            if ($customer->getTenant()->getId() !== $investment->getTenant()->getId()) {
                throw TenantMismatchException::forEntities(
                    'Customer',
                    $customer->getTenant()->getId(),
                    $investment->getTenant()->getId()
                );
            }
        }

        // 5. Tasa USD
        $currentUsdRate = $this->usdRateService->getCurrent();
        $usdRateSnapshot = $currentUsdRate?->getRate();

        // 6. Validar unitPrice
        $this->validateLineUnitPrices($cmd->lines, $investment);

        // 7. Crear Sale
        $sale = new Sale();
        $sale->setTenant($investment->getTenant());
        $sale->setCreatedBy($user);
        $sale->setInvestment($investment);
        $sale->setCustomer($customer);
        $sale->setCurrency($currency);
        $sale->setSaleDate($cmd->date);
        $sale->setNotes($cmd->notes);

        // [FIX-1] Pre-cargar stock disponible en memoria para todos los ítems
        // referenciados por el comando. Se descuenta en cada línea procesada
        // para que líneas posteriores del mismo ítem vean el stock correcto.
        $remainingStock = $this->preloadRemainingStock($cmd->lines, $investment);

        // [Fase 7.5] Fix N+1: todos los ítems de las líneas se cargan en UNA
        // sola query (findByIdsAndTenant) y el loop de createSaleLine usa el
        // array en memoria. Antes cada línea hacía su propia findByIdAndTenant.
        $itemsById = $this->loadItemsById($cmd->lines, $investment);

        // 8. Recuperado acumulado previo (ventas no anuladas)
        $accumulatedRecovered = $this->saleLineRepository->sumCostRecoveredByInvestment(
            $investment->getId(),
            $investment->getTenant()->getId(),
            false
        );
        $investmentTotal = $investment->getTotalInvestment();
        $saleLinesData = [];

        // 9. Crear SaleLines (validando stock contra $remainingStock en memoria)
        foreach ($cmd->lines as $lineCmd) {
            $accumulatedRecovered = $this->createSaleLine(
                $sale,
                $lineCmd,
                $investment,
                $usdRateSnapshot,
                $accumulatedRecovered,
                $investmentTotal,
                $saleLinesData,
                $remainingStock,
                $itemsById
            );
        }

        // 10. Persist + flush #1 (obtener IDs de Sale y SaleLines)
        $this->entityManager->persist($sale);
        $this->entityManager->flush();

        // 11. Crear movimientos con IDs reales (idempotency keys válidas)
        $this->createSaleMovements($sale, $saleLinesData, $cmd->date);

        // 12. Flush #2 (que el summary vea los movimientos)
        $this->entityManager->flush();

        // 13. Recalcular summary
        $this->summaryService->recompute($investment);

        // 14. Actualizar estado de la inversión
        $this->updateInvestmentState($investment);

        // 15. Flush #3 (persistir summary y cambio de estado)
        $this->entityManager->flush();

        return $sale;
    }

    /**
     * [FIX-1] Pre-carga el stock disponible por itemId.
     *
     * [Fase 7.6] Fix N+1: los ítems se agregan en UNA sola query
     * (getStockByItemIds con GROUP BY investment_item_id). Antes era un
     * getQuantityDeltaSum por ítem referenciado. Un ítem sin movimientos
     * queda en 0, igual que antes.
     *
     * @param RegisterSaleLineCommand[] $lines
     * @return array<int, int> Map itemId => stock disponible
     */
    private function preloadRemainingStock(array $lines, Investment $investment): array
    {
        $itemIds = [];
        foreach ($lines as $lineCmd) {
            $itemIds[$lineCmd->investmentItemId] = $lineCmd->investmentItemId;
        }

        // Los ítems sin movimientos no aparecen en el resultado agregado;
        // se pre inicializan en 0 para preservar la semántica anterior.
        // (Unión con el resultado a la izquierda: los SUM pisarían los 0.)
        return $this->movementRepository->getStockByItemIds(array_values($itemIds), $investment->getTenant()->getId())
            + array_fill_keys($itemIds, 0);
    }

    private function loadInvestment(int $investmentId, int $tenantId): Investment
    {
        $investment = $this->investmentRepository->findByIdAndTenant($investmentId, $tenantId);
        if ($investment === null) {
            throw InvestmentNotFoundException::forInvestment($investmentId);
        }
        return $investment;
    }

    /**
     * [Fase 7.5] Carga todos los ítems referenciados por las líneas en UNA
     * sola query (findByIdsAndTenant), indexados por id.
     *
     * Un id inexistente simplemente no aparece en el mapa; createSaleLine
     * lanza el mismo LogicException de antes al no encontrarlo.
     *
     * @param RegisterSaleLineCommand[] $lines
     * @return array<int, InvestmentItem> Map itemId => InvestmentItem
     */
    private function loadItemsById(array $lines, Investment $investment): array
    {
        $ids = [];
        foreach ($lines as $lineCmd) {
            $ids[$lineCmd->investmentItemId] = $lineCmd->investmentItemId;
        }

        $items = $this->itemRepository->findByIdsAndTenant(
            array_values($ids),
            $investment->getTenant()->getId()
        );

        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[$item->getId()] = $item;
        }

        return $itemsById;
    }

    /**
     * [FIX-1] Valida stock contra $remainingStock (en memoria) y lo descuenta.
     *
     * @param list<SaleLineData>  $saleLinesData Salida: acumula las líneas creadas
     * @param array<int, int>     $remainingStock Entrada/salida: stock por itemId
     * @param array<int, InvestmentItem> $itemsById Ítems precargados (Fase 7.5, fix N+1)
     *
     * @return string Recuperado acumulado tras crear la línea
     */
    private function createSaleLine(
        Sale                    $sale,
        RegisterSaleLineCommand $lineCmd,
        Investment              $investment,
        ?string                 $usdRateSnapshot,
        string                  $accumulatedRecoveredBefore,
        string                  $investmentTotal,
        array                   &$saleLinesData,
        array                   &$remainingStock,
        array                   $itemsById
    ): string
    {
        $itemId = $lineCmd->investmentItemId;
        $quantity = $lineCmd->quantity;
        $unitPrice = $lineCmd->unitPrice;

        // [Fase 7.5] Lookup en memoria: los ítems se cargaron en bloque en
        // loadItemsById() antes del loop (una sola query, sin N+1).
        $item = $itemsById[$itemId] ?? null;
        if ($item === null) {
            throw new \LogicException(sprintf('Ítem %d no encontrado.', $itemId));
        }

        if ($item->getInvestment()->getId() !== $investment->getId()) {
            throw ItemNotInInvestmentException::forItemInvestment(
                $itemId,
                $item->getInvestment()->getId(),
                $investment->getId()
            );
        }

        // [FIX-1] Validar stock en memoria (considera líneas anteriores del mismo comando)
        $available = $remainingStock[$itemId] ?? 0;
        if ($available < $quantity) {
            throw InsufficientStockException::forItem($itemId, $quantity, $available);
        }
        $remainingStock[$itemId] = $available - $quantity;

        // Crear SaleLine
        $line = new SaleLine();
        $line->setTenant($investment->getTenant());
        $line->setSale($sale);
        $line->setInvestmentItem($item);
        $line->setQuantity($quantity);
        $line->setUnitPrice($unitPrice);
        $line->setUsdRateSnapshot($usdRateSnapshot);
        $line->setRealUnitCostSnapshot($item->getRealUnitCost());
        $line->setCurrentCostSnapshot($item->getCurrentRealUnitCost());

        $sale->addLine($line);

        $recognizedProfit = $this->calculateRecognizedProfit(
            $line,
            $investment,
            $accumulatedRecoveredBefore,
            $investmentTotal
        );
        $line->applyRecognizedProfit($recognizedProfit);

        $saleLinesData[] = [
            'line' => $line,
            'item' => $item,
            'quantity' => $quantity,
            'unitCostSnapshot' => $item->getRealUnitCost(),
            'currentCostSnapshot' => $item->getCurrentRealUnitCost(),
            'costRecovered' => $line->getCostRecovered(),
        ];

        return bcadd($accumulatedRecoveredBefore, $line->getCostRecovered(), 2);
    }

    /**
     * @param list<SaleLineData> $saleLinesData
     */
    private function createSaleMovements(Sale $sale, array $saleLinesData, \DateTimeImmutable $saleDate): void
    {
        foreach ($saleLinesData as $data) {
            $line = $data['line'];
            $item = $data['item'];
            $quantity = $data['quantity'];

            $movement = new InventoryMovement();
            $movement->setTenant($sale->getTenant());
            $movement->setInvestmentItem($item);
            $movement->setMovementType(InventoryMovement::TYPE_SALE);
            $movement->setQuantityDelta(-$quantity);
            $movement->setUnitCostSnapshot($data['unitCostSnapshot']);
            $movement->setCurrentUnitCostSnapshot($data['currentCostSnapshot']);
            $movement->setReferenceType(InventoryMovement::REF_SALE_LINE);
            $movement->setReferenceId($line->getId());
            $movement->setIdempotencyKey(InventoryMovement::generateSaleKey($sale->getId(), $line->getId()));
            $movement->setMovementDate($saleDate);
            $movement->setNotes(sprintf('Venta %d', $sale->getId()));

            $this->entityManager->persist($movement);
            $item->addInventoryMovement($movement);
        }
    }

    private function calculateRecognizedProfit(
        SaleLine   $line,
        Investment $investment,
        string     $accumulatedRecoveredBefore,
        string     $investmentTotal
    ): string
    {
        return $this->recoveryRecognitionService->recognize(
            $investment,
            $line->getGrossProfitLine(),
            $line->getCostRecovered(),
            $accumulatedRecoveredBefore
        );
    }

    private function updateInvestmentState(Investment $investment): void
    {
        $summary = $this->summaryRepository->findByInvestment($investment->getId(), $investment->getTenant()->getId());
        if ($summary === null) {
            return;
        }

        $pending = $summary->getTotalPendingCurrent();

        if (bccomp($pending, '0', 2) <= 0) {
            $this->stateMachine->markRecovered($investment);
        } elseif (bccomp($pending, $investment->getTotalInvestment(), 2) < 0) {
            $this->stateMachine->markPartial($investment);
        }
    }

    /**
     * @param list<RegisterSaleLineCommand> $lines
     */
    private function validateLineUnitPrices(array $lines, Investment $investment): void
    {
        $scale = $this->settingsService->getCurrencyScale();
        foreach ($lines as $lineCmd) {
            $unitPrice = $lineCmd->unitPrice;

            if (!preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $unitPrice)) {
                throw new \InvalidArgumentException(sprintf(
                    'unitPrice "%s" debe ser numérico válido.',
                    $unitPrice
                ));
            }

            if (bccomp($unitPrice, '0', $scale) < 0) {
                throw new \InvalidArgumentException('unitPrice no puede ser negativo');
            }
        }
    }
}
