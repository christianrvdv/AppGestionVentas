<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\InventoryMovement;
use App\Entity\InvestmentSummary;
use App\Exception\Domain\InvestmentAlreadyConfirmedException;
use App\Exception\Domain\AllocationMismatchException;
use App\Repository\Contract\ExpenseAllocationRepositoryInterface;
use App\Service\ExpenseAllocationService;
use App\Service\InvestmentItemCalculatorService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Confirma una inversión (estado OPEN → confirmada).
 *
 * VALIDACIONES:
 *   1. Al menos 1 ítem.
 *   2. Todos los ítems: quantity > 0, unitCost definido (>= 0).
 *   3. Para cada gasto con isAllocated = true: suma allocations == monto gasto (±0.01).
 *   4. No confirmada previamente.
 *
 * ACCIONES:
 *   - Recalcula costos y precios (el calculator resuelve margen en cascada).
 *   - Inicializa currentRealUnitCost = realUnitCost, currentSuggestedPrice = suggestedPrice.
 *   - Crea InventoryMovement tipo PURCHASE por ítem con:
 *       unitCostSnapshot = realUnitCost
 *       currentUnitCostSnapshot = currentRealUnitCost
 *       idempotencyKey = InventoryMovement::generatePurchaseKey($item->getId())
 *   - Marca confirmedAt = now.
 *   - Actualiza totales de Investment (totalMerchandiseCost, totalExpenses, totalInvestment).
 *   - Crea InvestmentSummary inicial.
 *
 * NO HACE FLUSH: el llamador (controlador) hace flush único.
 */
final class InvestmentConfirmationService
{
    /** Tolerancia para validación de prorrateo (centavos). */
    private const ALLOCATION_TOLERANCE = '0.01';

    public function __construct(
        private readonly ExpenseAllocationRepositoryInterface $allocationRepository,
        private readonly ExpenseAllocationService $allocationService,
        private readonly InvestmentItemCalculatorService $calculatorService,
        private readonly SettingsService $settingsService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Confirma la inversión.
     *
     * @throws InvestmentAlreadyConfirmedException Si ya confirmada
     * @throws \LogicException                     Si validaciones fallan
     * @throws AllocationMismatchException         Si prorrateo no cuadra
     *
     * @return InvestmentSummary El summary creado y persistido
     */
    public function confirm(Investment $investment): InvestmentSummary
    {
        // 1. No confirmada previamente
        if ($investment->isConfirmed()) {
            throw InvestmentAlreadyConfirmedException::forInvestment($investment->getId());
        }

        // 2. Al menos 1 ítem
        $items = $investment->getItems();
        if ($items->isEmpty()) {
            throw new \LogicException('La inversión debe tener al menos un ítem para confirmarse.');
        }

        // 3. Validar ítems: quantity > 0, unitCost >= 0
        $scale = $this->settingsService->getCurrencyScale();
        foreach ($items as $item) {
            if ($item->getQuantity() <= 0) {
                throw new \LogicException(sprintf(
                    'Ítem %d (producto %s): quantity debe ser > 0.',
                    $item->getId(),
                    $item->getProduct()->getName()
                ));
            }
            $unitCost = $item->getUnitCost();
            if (bccomp($unitCost, '0', $scale) < 0) {
                throw new \LogicException(sprintf(
                    'Ítem %d (producto %s): unitCost no puede ser negativo.',
                    $item->getId(),
                    $item->getProduct()->getName()
                ));
            }
        }

        // 4. Validar prorrateo de gastos: suma allocations == monto gasto
        $this->validateExpenseAllocations($investment);

        // 5. Recalcular costos y precios (actualiza realUnitCost, suggestedPrice, etc.)
        //    El calculator resuelve la cascada de márgenes internamente (C.2).
        $this->calculatorService->recalculateAll($investment);

        // 7. Inicializar currentRealUnitCost y currentSuggestedPrice
        foreach ($items as $item) {
            if ($item->getCurrentRealUnitCost() === '0.00') {
                $item->setCurrentRealUnitCost($item->getRealUnitCost());
            }
            if ($item->getCurrentSuggestedPrice() === '0.00') {
                $item->setCurrentSuggestedPrice($item->getSuggestedPrice());
            }
        }

        // 8. Crear movimientos de inventario PURCHASE (entrada de mercancía)
        $this->createPurchaseMovements($investment);

        // 9. Marcar confirmado
        $investment->setConfirmedAt(new \DateTimeImmutable());
        $investment->setStatus(Investment::STATUS_OPEN); // OPEN confirmado = listo para vender

        // 10. Actualizar totales de la inversión
        $this->updateInvestmentTotals($investment);

        // 11. Crear y persistir InvestmentSummary inicial
        $summary = $this->createInitialSummary($investment);
        $this->entityManager->persist($summary);

        return $summary;
    }

    /**
     * Valida que cada gasto prorrateable tenga allocations que sumen su monto.
     */
    private function validateExpenseAllocations(Investment $investment): void
    {
        $tenantId = $investment->getTenant()->getId();
        foreach ($investment->getExpenses() as $expense) {
            if (!$expense->isAllocated()) {
                continue;
            }
            // Usa el repositorio directamente para sumar por gasto
            // Inyectamos el repositorio en el constructor
            $allocatedSum = $this->allocationRepository->sumByExpense($expense->getId(), $tenantId);
            $expenseAmount = $expense->getAmount();
            if (bccomp($allocatedSum, $expenseAmount, 2) !== 0) {
                // Tolerancia ±0.01 por redondeo
                $diff = bcsub($allocatedSum, $expenseAmount, 2);
                if (bccomp($diff, self::ALLOCATION_TOLERANCE, 2) > 0 ||
                    bccomp($diff, bcsub('0', self::ALLOCATION_TOLERANCE, 2), 2) < 0) {
                    throw AllocationMismatchException::forExpense(
                        $expense->getId(),
                        $expenseAmount,
                        $allocatedSum
                    );
                }
            }
        }
    }

    /**
     * Crea movimientos de inventario tipo PURCHASE para cada ítem.
     */
    private function createPurchaseMovements(Investment $investment): void
    {
        foreach ($investment->getItems() as $item) {
            $movement = new InventoryMovement();
            $movement->setTenant($investment->getTenant());
            $movement->setInvestmentItem($item);
            $movement->setMovementType(InventoryMovement::TYPE_PURCHASE);
            $movement->setQuantityDelta($item->getQuantity());
            $movement->setUnitCostSnapshot($item->getRealUnitCost());
            $movement->setCurrentUnitCostSnapshot($item->getCurrentRealUnitCost());
            $movement->setReferenceType(InventoryMovement::REF_INVESTMENT_ITEM);
            $movement->setReferenceId($item->getId());
            $movement->setIdempotencyKey(InventoryMovement::generatePurchaseKey($item->getId()));
            $movement->setMovementDate($investment->getConfirmedAt() ?? new \DateTimeImmutable());
            $movement->setNotes(sprintf('Compra inicial inversión %s', $investment->getCode()));

            $this->entityManager->persist($movement);
            $item->addInventoryMovement($movement);
        }
    }

    /**
     * Actualiza totales de la inversión.
     */
    private function updateInvestmentTotals(Investment $investment): void
    {
        $scale = $this->settingsService->getCurrencyScale();

        $totalMerchandise = '0.00';
        foreach ($investment->getItems() as $item) {
            $subtotal = bcmul($item->getUnitCost(), (string)$item->getQuantity(), $scale);
            $totalMerchandise = bcadd($totalMerchandise, $subtotal, $scale);
        }

        $totalExpenses = '0.00';
        foreach ($investment->getExpenses() as $expense) {
            $totalExpenses = bcadd($totalExpenses, $expense->getAmount(), $scale);
        }

        $totalInvestment = bcadd($totalMerchandise, $totalExpenses, $scale);

        $investment->setTotalMerchandiseCost($totalMerchandise);
        $investment->setTotalExpenses($totalExpenses);
        $investment->setTotalInvestment($totalInvestment);
    }

    /**
     * Crea InvestmentSummary inicial.
     */
    private function createInitialSummary(Investment $investment): InvestmentSummary
    {
        $summary = new InvestmentSummary();
        $summary->setTenant($investment->getTenant());
        $summary->setInvestment($investment);
        $summary->setTotalInvestment($investment->getTotalInvestment());
        $summary->setTotalInvestmentCurrent($investment->getTotalInvestment());
        $summary->setTotalRevaluationGainLoss('0.00');
        $summary->setTotalRecovered('0.00');
        $summary->setTotalRecoveredPerProduct('0.00');
        $summary->setTotalRecoveredInvestmentFirst('0.00');
        $summary->setTotalRecoveredCurrent('0.00');
        $summary->setTotalGrossProfit('0.00');
        $summary->setTotalRecognizedProfit('0.00');
        $summary->setTotalProfit('0.00');
        $summary->setTotalProfitPerProduct('0.00');
        $summary->setTotalProfitInvestmentFirst('0.00');
        $summary->setTotalPending($investment->getTotalInvestment());
        $summary->setTotalPendingCurrent($investment->getTotalInvestment());
        $summary->setRecoveryPct('0.00');
        $summary->setUnitsSold(0);
        $summary->setUnitsRemaining($this->countTotalUnits($investment));
        $summary->setUnitsLost(0);
        $summary->setConfirmedAt($investment->getConfirmedAt());

        // Sincroniza modo activo
        $summary->syncActiveMode($investment->getRecoveryMode());

        return $summary;
    }

    private function countTotalUnits(Investment $investment): int
    {
        $total = 0;
        foreach ($investment->getItems() as $item) {
            $total += $item->getQuantity();
        }
        return $total;
    }
}