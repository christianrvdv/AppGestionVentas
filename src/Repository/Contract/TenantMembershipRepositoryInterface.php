<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;

/**
 * Puerto de persistencia para las membresías (pertenencia usuario↔tenant).
 * Implementación Doctrine: App\Repository\TenantMembershipRepository.
 *
 * Invariante (ADR-0015): las consultas son POR USUARIO o POR TENANT
 * EXPLÍCITO — nunca "por el tenant activo de la sesión". El TenantFilter
 * solo restringe por ese tenant activo, y justamente el selector de
 * negocios necesita ver todos los tenants del usuario (findByUser) y el
 * panel de administración todos los usuarios de un negocio (findByTenant,
 * ADR-0016) — por eso las tres consultas corren con el filtro
 * deshabilitado (vía TenantFilterGuard) y devuelven únicamente filas del
 * usuario/tenant pedido.
 */
interface TenantMembershipRepositoryInterface
{
    /**
     * Todas las membresías del usuario, negocio por negocio.
     * Las de `isDefault = true` primero.
     *
     * @return TenantMembership[]
     */
    public function findByUser(AppUser $user): array;

    /**
     * Membresía concreta PERTENECIENTE AL USUARIO: la condición por
     * usuario va en la query, nunca se valida "a ojo" en el controller,
     * para que un id ajeno simplemente no exista.
     */
    public function findByIdAndUser(int $membershipId, int $userId): ?TenantMembership;

    /**
     * Todas las membresías de un tenant (negocio) concreto, con su
     * usuario hidratado (ADR-0016; vista global del super_admin vía
     * app:user:list --tenant).
     *
     * @return TenantMembership[]
     */
    public function findByTenant(Tenant $tenant): array;

    /**
     * Total de membresías del sistema (panel admin, Fase 6.1). Consulta
     * GLOBAL: su único consumidor es /admin (ROLE_SUPER_ADMIN).
     */
    public function countAll(): int;

    /**
     * Membresías por tenant para una tanda de tenants: los contadores
     * por fila de /admin/tenants (Fase 6.1). Como el resto de este
     * puerto, filtra por el tenant EXPLÍCITO pedido, nunca por el de la
     * sesión.
     *
     * @param list<int> $tenantIds
     *
     * @return array<int, int> tenantId => cantidad (solo los pedidos)
     */
    public function countByTenants(array $tenantIds): array;
}
