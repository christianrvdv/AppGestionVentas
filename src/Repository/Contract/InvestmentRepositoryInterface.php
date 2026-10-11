<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Investment;
use Doctrine\ORM\QueryBuilder;

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

    /**
     * TODAS las inversiones del tenant, sin filtrar por estado: el
     * listado de ventas filtra por inversión y una venta puede colgar de
     * una inversión recuperada o cerrada. Si el desplegable solo ofreciera
     * las activas, esa venta no tendría forma de filtrarse.
     *
     * @return Investment[]
     */
    public function findAllByTenant(int $tenantId): array;

    public function countByStatus(int $tenantId, string $status): int;

    /**
     * Cuenta inversiones de un tenant cuyo código empieza con el prefijo dado.
     * Usado para generar código secuencial INV-YYYYMMDD-NNN.
     */
    public function countByCodePrefix(int $tenantId, string $prefix): int;

    /**
     * QueryBuilder paginable para el listado web (Fase 2). Filtra siempre
     * por tenant (explícito, sin depender del SQLFilter) y opcionalmente
     * por estado; ordena por fecha DESC y código DESC.
     */
    public function createPaginatedQueryBuilder(int $tenantId, ?string $status): QueryBuilder;
}
