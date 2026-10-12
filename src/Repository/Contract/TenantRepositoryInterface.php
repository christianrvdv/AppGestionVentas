<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Tenant;
use Doctrine\ORM\QueryBuilder;

/**
 * Puerto de persistencia para tenants (empresas/organizaciones).
 * Implementación Doctrine: App\Repository\TenantRepository.
 */
interface TenantRepositoryInterface
{
    public function findById(int $id): ?Tenant;

    public function findBySlug(string $slug): ?Tenant;

    /**
     * @return Tenant[]
     */
    public function findActive(): array;

    /**
     * Total de tenants activos (panel admin, Fase 6.1). Consulta GLOBAL:
     * su único consumidor es /admin (ROLE_SUPER_ADMIN), nunca un tenant
     * concreto de la sesión.
     */
    public function countActive(): int;

    /**
     * Todos los tenants ordenados por nombre (desplegable de filtro del
     * panel admin, Fase 6.1). Consulta GLOBAL, ver countActive().
     *
     * @return Tenant[]
     */
    public function findAll(): array;

    /**
     * QueryBuilder paginable del listado /admin/tenants (Fase 6.1).
     * Orden estable por nombre; filtros opcionales por estado exacto y
     * por búsqueda insensible a mayúsculas en nombre/slug. Sin filtro
     * de tenant: es la vista global del super_admin.
     */
    public function createPaginatedQueryBuilder(?bool $isActive = null, ?string $q = null): QueryBuilder;
}
