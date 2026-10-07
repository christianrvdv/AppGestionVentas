<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\UsdRate;

/**
 * Puerto de persistencia para tasas de cambio USD.
 * Implementación Doctrine: App\Repository\UsdRateRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface UsdRateRepositoryInterface
{
    /**
     * Tasa vigente del tenant. Determinista: ante correcciones en la misma
     * fecha, devuelve la última insertada (createdAt DESC, id DESC).
     */
    public function findCurrent(int $tenantId): ?UsdRate;

    /**
     * Tasa vigente antes (o en) una fecha dada, considerando correcciones.
     */
    public function findCurrentBefore(int $tenantId, \DateTimeImmutable $date): ?UsdRate;

    /**
     * Tasa efectiva de una fecha concreta. Si hay correcciones, devuelve
     * la última insertada. Devuelve null si no hay ninguna tasa para esa fecha.
     */
    public function findEffectiveByDate(int $tenantId, \DateTimeImmutable $date): ?UsdRate;

    /**
     * @return UsdRate[]
     */
    public function findByDateRange(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array;
}
