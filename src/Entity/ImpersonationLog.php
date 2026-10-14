<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Auditoría de una sesión de impersonation de tenant (Fase 6.2, ADR-0019).
 *
 * Una fila por cada vez que un super_admin entra a un negocio:
 *   - started_at  se escribe al entrar;
 *   - ended_at    se escribe al salir (NULL = la sesión sigue abierta, o
 *                 se cortó sin pasar por /admin/impersonate/stop).
 *
 * Qué se audita y por qué: quién (super_admin_id), en qué negocio
 * (tenant_id) y cuándo (ambos timestamps). No se auditan las acciones
 * DENTRO del impersonation — el impersonation es de tenant, el usuario
 * autenticado sigue siendo el super_admin, y las acciones administrativas
 * ya quedan cubiertas por el resto del panel.
 *
 * La asociación se llama `tenant`, así que TenantFilter la cubre: durante
 * un impersonation el filtro está habilitado con ESE tenant, así que la
 * fila coincide igualmente. Además el cierre de la fila se busca con
 * TenantFilterGuard, porque una auditoría nunca debe depender del filtro
 * que esté activo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'impersonation_log')]
#[ORM\Index(name: 'idx_impersonation_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_impersonation_super_admin', columns: ['super_admin_id'])]
class ImpersonationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(
        name: 'super_admin_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private AppUser $superAdmin;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(
        name: 'tenant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Tenant $tenant;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function __construct(AppUser $superAdmin, Tenant $tenant)
    {
        $this->superAdmin = $superAdmin;
        $this->tenant = $tenant;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSuperAdmin(): AppUser
    {
        return $this->superAdmin;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /**
     * Cierra la sesión de auditoría. Idempotente: un segundo cierre (una
     * segunda petición de stop con la misma fila) no pisa el primer
     * timestamp.
     */
    public function close(?\DateTimeImmutable $endedAt = null): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        $this->endedAt = $endedAt ?? new \DateTimeImmutable();
    }
}
