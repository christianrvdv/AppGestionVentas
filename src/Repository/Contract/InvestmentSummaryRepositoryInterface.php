<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\InvestmentSummary;

/**
 * Puerto de persistencia para los resúmenes consolidados de inversión.
 * Implementación Doctrine: App\Repository\InvestmentSummaryRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface InvestmentSummaryRepositoryInterface
{
    public function findByInvestment(int $investmentId, int $tenantId): ?InvestmentSummary;

    /**
     * @return InvestmentSummary[]
     */
    public function findTopRecovered(int $tenantId, int $limit = 10): array;

    /**
     * Totales consolidados del tenant. Expone AMBOS modos de recuperación
     * y la vista revaluada, para que el dashboard no tenga que recomputar.
     *
     * @return array{
     *   totalInvestment: string,
     *   totalInvestmentCurrent: string,
     *   totalRecovered: string,
     *   totalRecoveredPerProduct: string,
     *   totalRecoveredInvestmentFirst: string,
     *   totalRecoveredCurrent: string,
     *   totalPending: string,
     *   totalPendingCurrent: string,
     *   totalGrossProfit: string,
     *   totalRecognizedProfit: string,
     *   totalProfit: string,
     *   totalProfitPerProduct: string,
     *   totalProfitInvestmentFirst: string
     * }
     */
    public function sumTotalsByTenant(int $tenantId): array;
}
