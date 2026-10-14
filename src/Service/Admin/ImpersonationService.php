<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Doctrine\TenantFilterGuard;
use App\Entity\AppUser;
use App\Entity\ImpersonationLog;
use App\Entity\Tenant;
use App\Security\ActiveTenantSession;
use App\Security\ImpersonationSession;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Entrada y salida del impersonation de tenant (Fase 6.2, ADR-0019).
 *
 * El impersonation SOLO cambia el tenant activo de la sesión: el usuario
 * autenticado, su token y sus roles no se tocan. Con la marca puesta,
 * TenantFilterListener habilita el SQLFilter con ese tenant, que es lo
 * que hace que el super_admin vea los datos del negocio.
 *
 * Cada entrada deja una fila en impersonation_log y cada salida la
 * cierra (ended_at), así que la auditoría sobrevive a reinicios y se
 * puede consultar desde la BD.
 */
final class ImpersonationService
{
    public function __construct(
        private readonly ActiveTenantSession $activeTenantSession,
        private readonly ImpersonationSession $impersonationSession,
        private readonly TenantFilterGuard $filterGuard,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Activa el impersonation: persiste la fila de auditoría, escribe los
     * claims de sesión y pone el tenant activo.
     *
     * La fila se persiste ANTES de tocar la sesión: si el flush falla, no
     * queda una marca sin auditoría.
     *
     * @throws \LogicException si ya hay un impersonation activo o si el
     *         usuario no es super_admin (el controller lo decide antes,
     *         esto es la defensa en profundidad).
     */
    public function start(Tenant $tenant, AppUser $superAdmin): void
    {
        if ($this->impersonationSession->isActive()) {
            throw new \LogicException('Ya hay un impersonation activo; sálelo antes de entrar a otro negocio.');
        }

        if (!$superAdmin->isSuperAdmin()) {
            throw new \LogicException('Solo un super_admin puede entrar a un negocio en modo soporte.');
        }

        $log = new ImpersonationLog($superAdmin, $tenant);
        $this->em->persist($log);
        $this->em->flush();

        $this->impersonationSession->start(
            $tenant->getId(),
            $superAdmin->getId(),
            $log->getId(),
            $this->activeTenantSession->getTenantId(),
        );

        $this->activeTenantSession->setTenantId($tenant->getId());
    }

    /**
     * Desactiva el impersonation: cierra la auditoría, limpia los claims
     * y restaura el tenant que tenía la sesión antes (o la deja sin
     * negocio, que es el caso normal de un super_admin).
     */
    public function stop(): void
    {
        $logId = $this->impersonationSession->getLogId();
        $previousTenantId = $this->impersonationSession->getPreviousTenantId();

        if ($logId !== null) {
            // La auditoría no depende del filtro activo: durante un
            // impersonation el TenantFilter está habilitado, y cerrar la
            // fila es una operación de registro, no una lectura de datos
            // de negocio.
            $log = $this->filterGuard->withoutTenantFilter(
                fn (): ?ImpersonationLog => $this->em->find(ImpersonationLog::class, $logId)
            );
            $log?->close();
        }

        $this->impersonationSession->stop();

        if ($previousTenantId === null) {
            $this->activeTenantSession->clear();
        } else {
            $this->activeTenantSession->setTenantId($previousTenantId);
        }

        $this->em->flush();
    }
}
