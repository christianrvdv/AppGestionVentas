<?php

declare(strict_types=1);

namespace App\Service\Sale;

use App\Entity\AppUser;
use App\Entity\InventoryMovement;
use App\Entity\Investment;
use App\Entity\Sale;
use App\Entity\SaleLine;
use App\Exception\Domain\SaleHasPaymentsException;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Service\Investment\InvestmentStateMachine;
use App\Service\Investment\InvestmentSummaryService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Anulación de ventas (devolución de cliente).
 *
 * POLÍTICA (ADR-0003):
 *   - Solo ventas SIN pagos aplicados pueden anularse.
 *   - Crea InventoryMovement tipo CUSTOMER_RETURN por cada línea.
 *   - recognizedProfitLine de las líneas se pone a 0 (la venta no existió).
 *   - Marca voidedAt, voidedBy, voidReason.
 *   - Recalcula InvestmentSummary.
 *   - Actualiza estado de inversión (puede volver a OPEN/PARTIAL).
 *
 * POLÍTICA DE FLUSH (ADR-0017): hace UN flush intermedio después de marcar
 * la anulación y antes de recalcular el summary. El summary se calcula con
 * consultas SQL: si la anulación siguiera solo en memoria, esas consultas
 * verían la venta como activa y la recuperación no bajaría. Y hace un
 * flush FINAL para persistir el resultado de la recompute y el cambio de
 * estado de la inversión: recompute() muta una entidad ya existente y
 * setStatus() marca sucia otra, y ninguna de las dos llega a la base sin
 * flush. No se lo puede pedir al llamador porque no sabe que hubo una
 * recompute, y la siguiente request leería un summary viejo.
 *
 * UNA SOLA RECOMPUTE: void() delega el recálculo en updateInvestmentState(),
 * que es quien lo llama. Recalcular dos veces (una en void() y otra en
 * updateInvestmentState()) no es solo redundante: si el summary no existe,
 * las dos llamadas crean y persisten cada una una InvestmentSummary nueva
 * y el flush final revienta contra el índice único uniq_summary_investment
 * (ver SaleVoidVoidWithoutSummaryTest).
 */
final class SaleVoidService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly InvestmentStateMachine $stateMachine,
        private readonly InvestmentSummaryService $summaryService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Anula una venta.
     *
     * @param Sale      $sale    Venta a anular (debe estar cargada con líneas)
     * @param string    $reason  Motivo de anulación (requerido)
     * @param AppUser   $user    Usuario que anula
     *
     * @throws SaleHasPaymentsException Si la venta tiene pagos aplicados
     */
    public function void(Sale $sale, string $reason, AppUser $user): void
    {
        // 1. Validar que no tenga pagos
        $payments = $this->paymentRepository->findBySale($sale->getId(), $sale->getTenant()->getId());
        if (!empty($payments)) {
            throw SaleHasPaymentsException::forSale($sale->getId());
        }

        // 2. Validar que no esté ya anulada
        if ($sale->isVoided()) {
            return; // Idempotente
        }

        // 3. Para cada línea, crear movimiento CUSTOMER_RETURN
        foreach ($sale->getLines() as $line) {
            $this->createReturnMovement($sale, $line);
            // Poner recognizedProfitLine a 0 (la venta no existió)
            $line->applyRecognizedProfit('0.00');
        }

        // 4. Marcar venta como anulada
        $sale->setVoidedAt(new \DateTimeImmutable());
        $sale->setVoidReason($reason);
        $sale->setVoidedBy($user);

        // 5. Flush intermedio: sin él, la recompute de abajo se recalcula
        //    contra una BD donde la venta todavía figura como activa
        //    (voided_at está solo en memoria) y la recuperación no baja.
        $this->entityManager->flush();

        // 6. Recalcular summary y, con ese resultado, actualizar el estado
        //    de la inversión (puede retroceder a OPEN/PARTIAL). Una única
        //    recompute, dentro de updateInvestmentState().
        $investment = $sale->getInvestment();
        $this->updateInvestmentState($investment);

        // 7. Flush final: el summary recalculado y el estado de la inversión
        //    siguen solo en memoria (ADR-0017). Sin este flush, la siguiente
        //    request leería un summary con la venta todavía como vendida.
        $this->entityManager->flush();
    }

    /**
     * Crea movimiento de inventario tipo CUSTOMER_RETURN.
     * Devuelve stock al ítem.
     */
    private function createReturnMovement(Sale $sale, SaleLine $line): void
    {
        $item = $line->getInvestmentItem();

        $movement = new InventoryMovement();
        $movement->setTenant($sale->getTenant());
        $movement->setInvestmentItem($item);
        $movement->setMovementType(InventoryMovement::TYPE_CUSTOMER_RETURN);
        $movement->setQuantityDelta($line->getQuantity()); // Positivo: devuelve stock
        $movement->setUnitCostSnapshot($line->getRealUnitCostSnapshot());
        $movement->setCurrentUnitCostSnapshot($line->getCurrentCostSnapshot());
        $movement->setReferenceType(InventoryMovement::REF_SALE_LINE);
        $movement->setReferenceId($line->getId());
        $movement->setIdempotencyKey(InventoryMovement::generateReturnKey($sale->getId(), $line->getId()));
        $movement->setMovementDate(new \DateTimeImmutable());
        $movement->setNotes(sprintf('Devolución venta %d', $sale->getId()));

        $this->entityManager->persist($movement);
        $item->addInventoryMovement($movement);
    }

    /**
     * Actualiza estado de inversión tras anulación.
     *
     * Es el ÚNICO punto en el que se recalcula el summary: recompute() se
     * llama una sola vez por anulación y el resultado alimenta la decisión
     * de estado. Si void() también llamara a recompute(), con un summary
     * inexistente se encolarían dos INSERT y el flush final violaría
     * uniq_summary_investment.
     */
    private function updateInvestmentState(Investment $investment): void
    {
        $summary = $this->summaryService->recompute($investment);
        $pending = $summary->getTotalPendingCurrent();

        if (bccomp($pending, '0', 2) <= 0) {
            // Totalmente recuperado
            $this->stateMachine->markRecovered($investment);
        } elseif (bccomp($pending, $investment->getTotalInvestment(), 2) < 0) {
            // Parcialmente recuperado
            $this->stateMachine->markPartial($investment);
        } else {
            // Sin recuperar nada, vuelve a OPEN
            $investment->setStatus(Investment::STATUS_OPEN);
        }
    }
}