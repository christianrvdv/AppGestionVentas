<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\InvestmentItem;

/**
 * Puerto de persistencia para los ítems de una inversión.
 * Implementación Doctrine: App\Repository\InvestmentItemRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface InvestmentItemRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?InvestmentItem;

    /**
     * @return InvestmentItem[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array;

    /**
     * Ítems con stock remanente (SUM(quantity_delta) > 0).
     *
     * @return InvestmentItem[]
     */
    public function findWithRemainingStock(int $tenantId): array;

    /**
     * Ítems de una inversión con stock remanente.
     *
     * @return InvestmentItem[]
     */
    public function findWithRemainingStockByInvestment(int $investmentId, int $tenantId): array;

    /**
     * Ítems de un producto con stock remanente.
     *
     * @return InvestmentItem[]
     */
    public function findWithRemainingStockByProduct(int $productId, int $tenantId): array;

    /**
     * @param int[] $ids
     *
     * @return InvestmentItem[]
     */
    public function findByIdsAndTenant(array $ids, int $tenantId): array;

    public function countByInvestment(int $investmentId, int $tenantId): int;
}
