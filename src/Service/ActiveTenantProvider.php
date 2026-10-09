<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\Contract\TenantRepositoryInterface;

/**
 * Resuelve la ENTIDAD Tenant del negocio activo de la sesión (ADR-0015).
 *
 * TenantContext solo guarda el id (se llena en kernel.request desde la
 * sesión); este servicio lo traduce a entidad y expone el nombre para
 * el nav (twig.globals.active_tenant). Los controllers y servicios que
 * necesitan el tenant "de este usuario en esta request" deben pedirlo
 * aquí, nunca asumir un tenant fijo ni leerlo del request.
 *
 * Consulta la entidad por PK id: con el identity map de Doctrine no hay
 * query si el tenant ya está hidratado en la request.
 */
final class ActiveTenantProvider
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantRepositoryInterface $tenantRepository,
    ) {
    }

    public function getTenantId(): ?int
    {
        return $this->tenantContext->getTenantId();
    }

    public function getTenant(): ?Tenant
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId === null ? null : $this->tenantRepository->findById($tenantId);
    }

    /**
     * Para contextos donde ya se sabe que hay tenant activo (tras
     * RequireTenantListener, dentro de una ruta protegida). Si no lo hay,
     * es un bug de wiring, no un caso de usuario: falla ruidosamente.
     */
    public function requireTenant(): Tenant
    {
        $tenant = $this->getTenant();

        if ($tenant === null) {
            throw new \LogicException('La sesión no tiene un negocio activo.');
        }

        return $tenant;
    }

    public function getName(): ?string
    {
        return $this->getTenant()?->getName();
    }
}
