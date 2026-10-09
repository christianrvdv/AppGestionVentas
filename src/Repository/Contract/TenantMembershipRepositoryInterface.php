<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\AppUser;
use App\Entity\TenantMembership;

/**
 * Puerto de persistencia para las membresías (pertenencia usuario↔tenant).
 * Implementación Doctrine: App\Repository\TenantMembershipRepository.
 *
 * Invariante (ADR-0015): las consultas son POR USUARIO. El TenantFilter
 * solo restringe por el tenant activo de la sesión, y justamente el
 * selector de negocios necesita ver todos los tenants del usuario — por
 * eso ambas consultas corren con el filtro deshabilitado (vía
 * TenantFilterGuard) y devuelven únicamente filas del usuario pedido.
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
}
