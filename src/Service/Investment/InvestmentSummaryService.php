<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentSummary;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Repository\Contract\ItemCostRevaluationRepositoryInterface;
use App\Repository\Contract\SaleLineRepositoryInterface;

/**
 * Recalcula InvestmentSummary desde repositorios. Idempotente.
 *
 * [FIX-5] totalPending y totalPendingCurrent se setean según el modo ACTIVO
 *         (antes siempre usaban valores del modo PER_PRODUCT). Además,
 *         totalRecoveredCurrent también se setea según el modo activo.
 *
 * FLUJO:
 *   1. Leer datos crudos (saleLines, stock, revaluación).
 *   2. Calcular métricas de ambos modos vía RecoveryRecognitionService.
 *   3. Elegir los valores del modo activo para pending/recovered/profit.
 *   4. syncActiveMode() sincroniza recovered y profit (delegado).
 */
final class InvestmentSummaryService
{
    public function __construct(
        private readonly SaleLineRepositoryInterface            $saleLineRepository,
        private readonly InventoryMovementRepositoryInterface   $movementRepository,
        private readonly ItemCostRevaluationRepositoryInterface $revaluationRepository,
        private readonly InvestmentSummaryRepositoryInterface   $summaryRepository,
        private readonly RecoveryRecognitionService             $recoveryRecognitionService
    )
    {
    }

    public function recompute(Investment $investment): InvestmentSummary
    {
        $tenantId = $investment->getTenant()->getId();
        $investmentId = $investment->getId();

        // 1. Obtener o crear summary
        $summary = $this->summaryRepository->findByInvestment($investmentId, $tenantId);
        if ($summary === null) {
            $summary = new InvestmentSummary();
            $summary->setTenant($investment->getTenant());
            $summary->setInvestment($investment);
        }

        // 2. Totales base
        $totalInvestment = $investment->getTotalInvestment();

        // 3. Revaluación latente
        $totalRevaluationGainLoss = $this->revaluationRepository->sumGainLossByInvestment($investmentId, $tenantId);
        $totalInvestmentCurrent = bcadd($totalInvestment, $totalRevaluationGainLoss, 2);

        // 4. SaleLines → datos crudos
        $saleLines = $this->saleLineRepository->findByInvestment($investmentId, $tenantId, false);

        $saleLinesData = [];
        $unitsSold = 0;
        foreach ($saleLines as $line) {
            $saleLinesData[] = [
                'costRecovered' => $line->getCostRecovered(),
                'grossProfit' => $line->getGrossProfitLine(),
                'recognizedProfit' => $line->getRecognizedProfitLine(),
            ];
            $unitsSold += $line->getQuantity();
        }

        // 5. Métricas de recuperación (modo histórico)
        $recoveryMetrics = $this->recoveryRecognitionService->computeFromLines($investment, $saleLinesData);

        $totalRecoveredPerProduct = $recoveryMetrics['totalRecoveredPerProduct'];
        $totalRecoveredInvestmentFirst = $recoveryMetrics['totalRecoveredInvestmentFirst'];
        $totalProfitPerProduct = $recoveryMetrics['totalProfitPerProduct'];
        $totalProfitInvestmentFirst = $recoveryMetrics['totalProfitInvestmentFirst'];
        $totalGrossProfit = $recoveryMetrics['totalGrossProfit'];
        $totalRecognizedProfit = $recoveryMetrics['totalRecognizedProfit'];

        // 6. Métricas "current" (con inversión ajustada por revaluación)
        $currentMetrics = $this->recoveryRecognitionService->computeFromLinesWithCustomTotal(
            $totalInvestmentCurrent,
            $saleLinesData
        );
        $totalRecoveredCurrentPerProduct = $currentMetrics['totalRecoveredPerProduct'];
        $totalRecoveredCurrentInvestmentFirst = $currentMetrics['totalRecoveredInvestmentFirst'];

        // 7. Inventario
        $stockByItem = $this->movementRepository->getStockByInvestment($investmentId, $tenantId);
        $unitsRemaining = 0;
        foreach ($stockByItem as $data) {
            $unitsRemaining += $data['balance'];
        }
        $unitsLost = $this->movementRepository->getLostQuantityByInvestment($investmentId, $tenantId);

        // 8. Pendientes por modo
        $totalPendingPerProduct = bcsub($totalInvestment, $totalRecoveredPerProduct, 2);
        $totalPendingInvestmentFirst = bcsub($totalInvestment, $totalRecoveredInvestmentFirst, 2);
        $totalPendingCurrentPerProduct = bcsub($totalInvestmentCurrent, $totalRecoveredCurrentPerProduct, 2);
        $totalPendingCurrentInvestmentFirst = bcsub($totalInvestmentCurrent, $totalRecoveredCurrentInvestmentFirst, 2);

        // 9. Recovery %
        $recoveryPct = $this->calculateRecoveryPct($totalInvestment, $totalRecoveredPerProduct);

        // 10. Rellenar summary (valores base)
        $summary->setTotalInvestment($totalInvestment);
        $summary->setTotalInvestmentCurrent($totalInvestmentCurrent);
        $summary->setTotalRevaluationGainLoss($totalRevaluationGainLoss);
        $summary->setTotalRecoveredPerProduct($totalRecoveredPerProduct);
        $summary->setTotalRecoveredInvestmentFirst($totalRecoveredInvestmentFirst);
        $summary->setTotalGrossProfit($totalGrossProfit);
        $summary->setTotalRecognizedProfit($totalRecognizedProfit);
        $summary->setTotalProfitPerProduct($totalProfitPerProduct);
        $summary->setTotalProfitInvestmentFirst($totalProfitInvestmentFirst);
        $summary->setUnitsSold($unitsSold);
        $summary->setUnitsRemaining($unitsRemaining);
        $summary->setUnitsLost($unitsLost);
        $summary->setRecoveryPct($recoveryPct);

        // 11. [FIX-5] Elegir valores del modo activo
        $isInvestmentFirst = $investment->getRecoveryMode() === Investment::RECOVERY_INVESTMENT_FIRST;

        $totalPendingActive = $isInvestmentFirst ? $totalPendingInvestmentFirst : $totalPendingPerProduct;
        $totalPendingCurrentActive = $isInvestmentFirst ? $totalPendingCurrentInvestmentFirst : $totalPendingCurrentPerProduct;
        $totalRecoveredCurrentActive = $isInvestmentFirst ? $totalRecoveredCurrentInvestmentFirst : $totalRecoveredCurrentPerProduct;

        $summary->setTotalPending($totalPendingActive);
        $summary->setTotalPendingCurrent($totalPendingCurrentActive);
        $summary->setTotalRecoveredCurrent($totalRecoveredCurrentActive);

        // 12. syncActiveMode() ajusta totalRecovered y totalProfit
        $summary->syncActiveMode($investment->getRecoveryMode());

        // 13. Timestamps
        if ($summary->getConfirmedAt() === null && $investment->getConfirmedAt() !== null) {
            $summary->setConfirmedAt($investment->getConfirmedAt());
        }
        if ($summary->getClosedAt() === null && $investment->getClosedAt() !== null) {
            $summary->setClosedAt($investment->getClosedAt());
        }

        return $summary;
    }

    private function calculateRecoveryPct(string $totalInvestment, string $totalRecovered): string
    {
        if (bccomp($totalInvestment, '0', 2) === 0) {
            return '0.00';
        }
        $pct = bcdiv(bcmul($totalRecovered, '100', 4), $totalInvestment, 2);
        if (bccomp($pct, '100', 2) > 0) {
            return '100.00';
        }
        return $pct;
    }
}
