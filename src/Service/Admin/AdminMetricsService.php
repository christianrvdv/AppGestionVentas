<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Repository\AppUserRepository;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Repository\Contract\TenantRepositoryInterface;

/**
 * Métricas globales del panel de administración (Fase 6.1).
 *
 * Todas las consultas son GLOBALES por diseño: su único consumidor es
 * /admin, blindado con ROLE_SUPER_ADMIN en security.yaml (el rol es
 * global por definición, sin membresía ni tenant activo), y
 * TenantFilterListener ya deja el SQLFilter deshabilitado para ese rol
 * (ADR-0016). Así que aquí NO hay filtro de tenant que aplicar: los
 * repositorios devuelven conteos de todo el sistema y los métodos que
 * van a detalle de un negocio reciben el tenant EXPLÍCITO.
 *
 * Contadores: son conteos de filas crudos (inversiones canceladas y
 * ventas anuladas incluidas en los listados de tenant). Las únicas
 * métricas agregadas que miden negocio real son las que excluyen
 * anuladas, y eso se indica en cada método de repositorio.
 */
final readonly class AdminMetricsService
{
    /**
     * El UserRepository se tipa por CONCRETO a propósito: su puerto
     * (AppUserRepositoryInterface) está blindado por un test de
     * reflexión (ADR-0015: lookups de identidad SIN parámetros de
     * tenant), y las consultas del panel viven en la clase concreta.
     *
     * @param TenantRepositoryInterface           $tenants
     * @param AppUserRepository                   $users
     * @param TenantMembershipRepositoryInterface $memberships
     * @param InvestmentRepositoryInterface       $investments
     * @param SaleRepositoryInterface             $sales
     */
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private AppUserRepository $users,
        private TenantMembershipRepositoryInterface $memberships,
        private InvestmentRepositoryInterface $investments,
        private SaleRepositoryInterface $sales,
    ) {
    }

    /**
     * Contadores del dashboard /admin.
     *
     * @return array{
     *     tenants_activos: int,
     *     usuarios: int,
     *     membresias: int,
     *     inversiones: int,
     *     ventas: int
     * }
     */
    public function globalMetrics(): array
    {
        return [
            'tenants_activos' => $this->tenants->countActive(),
            'usuarios' => $this->users->countAll(),
            'membresias' => $this->memberships->countAll(),
            'inversiones' => $this->investments->countAll(),
            'ventas' => $this->sales->countAll(),
        ];
    }

    /**
     * Métricas de UN negocio para la ficha /admin/tenants/{id}. El tenant
     * va explícito en las cláusulas: el SQLFilter está apagado, así que
     * ese parámetro ES el aislamiento (nada de otro negocio puede entrar
     * en estas cifras).
     *
     * @return array{
     *     usuarios: int,
     *     inversiones: int,
     *     ventas: int,
     *     monto_vendido: float
     * }
     */
    public function tenantMetrics(int $tenantId): array
    {
        $memberships = $this->memberships->countByTenants([$tenantId]);
        $investments = $this->investments->countByTenants([$tenantId]);
        $sales = $this->sales->countByTenants([$tenantId]);
        $salesTotal = $this->sales->sumTotalByTenant($tenantId);

        return [
            'usuarios' => $memberships[$tenantId] ?? 0,
            'inversiones' => $investments[$tenantId] ?? 0,
            'ventas' => $sales[$tenantId] ?? 0,
            'monto_vendido' => (float) $salesTotal,
        ];
    }
}
