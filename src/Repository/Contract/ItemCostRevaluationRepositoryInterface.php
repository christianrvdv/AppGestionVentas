<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\ItemCostRevaluation;

/**
 * Puerto de persistencia para revaluaciones de costo de ítems.
 * Implementación Doctrine: App\Repository\ItemCostRevaluationRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface ItemCostRevaluationRepositoryInterface
{
    /**
     * @return ItemCostRevaluation[]
     */
    public function findByItem(int $itemId, int $tenantId): array;

    /**
     * @return ItemCostRevaluation[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array;

    public function findLastByItem(int $itemId, int $tenantId): ?ItemCostRevaluation;

    /**
     * Suma de ganancia/pérdida latente por revaluación de una inversión.
     * Positivo = el costo subió (pérdida latente).
     * Negativo = el costo bajó (ganancia latente).
     */
    public function sumGainLossByInvestment(int $investmentId, int $tenantId): string;

    /**
     * @return ItemCostRevaluation[]
     */
    public function findByDateRange(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array;
}
