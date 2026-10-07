<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Investment;

/**
 * Puerto de persistencia para inversiones (lotes de compra).
 * Implementación Doctrine: App\Repository\InvestmentRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface InvestmentRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?Investment;

    /**
     * @return Investment[]
     */
    public function findByDateRange(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array;

    /**
     * @return Investment[]
     */
    public function findByStatus(int $tenantId, string $status): array;

    /**
     * Suma de inversiones del período. Excluye CANCELLED: una inversión
     * cancelada nunca se ejecutó, no debe contarse como capital invertido.
     */
    public function sumInvestmentsByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): string;

    /**
     * Inversiones candidatas a revaluación: abiertas/parciales, no canceladas
     * y con tasa USD definida.
     *
     * @return Investment[]
     */
    public function findRevaluable(int $tenantId): array;

    /**
     * @return Investment[]
     */
    public function findActiveByTenant(int $tenantId): array;

    public function countByStatus(int $tenantId, string $status): int;
}
