<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\Investment;

/**
 * Servicio centralizado para cálculo de ganancia reconocida (ADR-0005).
 *
 * [FIX-4] computeFromLines() y computeFromLinesWithCustomTotal() calculaban
 *         totalProfitPerProduct sumando `recognizedProfit` (el valor almacenado
 *         en SaleLine), que refleja el modo ACTIVO al momento de la venta.
 *         Si el modo es INVESTMENT_FIRST, ese valor es menor que el que
 *         correspondería a PER_PRODUCT. Ahora ambos totales se recalculan
 *         independientemente desde grossProfit, sin confiar en el valor
 *         almacenado (que solo sirve como referencia para totalRecognizedProfit).
 *
 * Reglas:
 *   PER_PRODUCT:       reconocido = max(0, grossProfit)   [no se reconoce pérdida]
 *   INVESTMENT_FIRST:  reconocido = min( max(0, excess), max(0, grossProfit) )
 *                      donde excess = acumuladoRecuperado - inversiónTotal
 */
final class RecoveryRecognitionService
{
    /**
     * Ganancia reconocida para una línea individual (usado por SaleService al
     * crear la línea). Delega al algoritmo correspondiente al modo.
     */
    public function recognize(
        Investment $investment,
        string     $grossProfitLine,
        string     $costRecoveredLine,
        string     $accumulatedRecoveredBefore
    ): string
    {
        if ($investment->getRecoveryMode() === Investment::RECOVERY_PER_PRODUCT) {
            return bccomp($grossProfitLine, '0', 2) > 0 ? $grossProfitLine : '0.00';
        }

        // INVESTMENT_FIRST
        $investmentTotal = $investment->getTotalInvestment();
        $accumulatedAfter = bcadd($accumulatedRecoveredBefore, $costRecoveredLine, 2);
        $excess = bcsub($accumulatedAfter, $investmentTotal, 2);

        if (bccomp($excess, '0', 2) <= 0) {
            return '0.00';
        }

        $cap = bccomp($grossProfitLine, '0', 2) > 0 ? $grossProfitLine : '0.00';

        return bccomp($excess, $cap, 2) > 0 ? $cap : $excess;
    }

    /**
     * Métricas agregadas de recuperación para una inversión (usado por summary).
     *
     * @param array $saleLines Cada elemento: ['costRecovered' => string, 'grossProfit' => string, 'recognizedProfit' => string]
     * @return array{
     *   totalRecoveredPerProduct: string,
     *   totalRecoveredInvestmentFirst: string,
     *   totalProfitPerProduct: string,
     *   totalProfitInvestmentFirst: string,
     *   totalGrossProfit: string,
     *   totalRecognizedProfit: string
     * }
     */
    public function computeFromLines(Investment $investment, array $saleLines): array
    {
        return $this->computeMetrics($investment->getTotalInvestment(), $saleLines);
    }

    /**
     * Igual que computeFromLines pero con un total de inversión personalizado
     * (usado para cálculos "current" con inversión ajustada por revaluación).
     */
    public function computeFromLinesWithCustomTotal(string $customInvestmentTotal, array $saleLines): array
    {
        return $this->computeMetrics($customInvestmentTotal, $saleLines);
    }

    /**
     * [FIX-4] Núcleo del cálculo. Ambos modos se computan independientemente.
     */
    private function computeMetrics(string $investmentTotal, array $saleLines): array
    {
        $totalRecoveredPerProduct = '0.00';
        $totalRecoveredInvestmentFirst = '0.00';
        $totalProfitPerProduct = '0.00';
        $totalProfitInvestmentFirst = '0.00';
        $totalGrossProfit = '0.00';
        $totalRecognizedProfit = '0.00';

        $accumulatedRecovered = '0.00';

        foreach ($saleLines as $line) {
            $costRecovered = $line['costRecovered'];
            $grossProfit = $line['grossProfit'];
            $recognizedProfit = $line['recognizedProfit'] ?? '0.00';

            // ----- PER_PRODUCT -----
            // Reconocimiento = max(0, grossProfit) — no se reconoce pérdida
            $totalRecoveredPerProduct = bcadd($totalRecoveredPerProduct, $costRecovered, 2);
            $ppLineProfit = bccomp($grossProfit, '0', 2) > 0 ? $grossProfit : '0.00';
            $totalProfitPerProduct = bcadd($totalProfitPerProduct, $ppLineProfit, 2);

            // ----- INVESTMENT_FIRST -----
            $accumulatedRecovered = bcadd($accumulatedRecovered, $costRecovered, 2);
            $excess = bcsub($accumulatedRecovered, $investmentTotal, 2);

            if (bccomp($excess, '0', 2) <= 0) {
                $ifLineProfit = '0.00';
            } else {
                $cap = bccomp($grossProfit, '0', 2) > 0 ? $grossProfit : '0.00';
                $ifLineProfit = bccomp($excess, $cap, 2) > 0 ? $cap : $excess;
            }

            $totalRecoveredInvestmentFirst = bcadd($totalRecoveredInvestmentFirst, $costRecovered, 2);
            $totalProfitInvestmentFirst = bcadd($totalProfitInvestmentFirst, $ifLineProfit, 2);

            // ----- Totales independientes del modo -----
            $totalGrossProfit = bcadd($totalGrossProfit, $grossProfit, 2);
            $totalRecognizedProfit = bcadd($totalRecognizedProfit, $recognizedProfit, 2);
        }

        return [
            'totalRecoveredPerProduct' => $totalRecoveredPerProduct,
            'totalRecoveredInvestmentFirst' => $totalRecoveredInvestmentFirst,
            'totalProfitPerProduct' => $totalProfitPerProduct,
            'totalProfitInvestmentFirst' => $totalProfitInvestmentFirst,
            'totalGrossProfit' => $totalGrossProfit,
            'totalRecognizedProfit' => $totalRecognizedProfit,
        ];
    }
}
