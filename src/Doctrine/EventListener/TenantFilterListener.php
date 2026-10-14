<?php

declare(strict_types=1);

namespace App\Doctrine\EventListener;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\AppUser;
use App\Security\ActiveTenantSession;
use App\Security\ImpersonationSession;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Habilita y configura el TenantFilter al inicio de cada request HTTP.
 *
 * Debe ejecutarse DESPUÉS del firewall de Symfony (que corre a prioridad
 * 8) y después de RequireTenantListener (prioridad 6, que redirige a
 * /select-tenant o /onboarding si la sesión no tiene negocio activo).
 * Usamos prioridad 5.
 *
 * Responsabilidades:
 *   1. Poblar TenantContext con el negocio ACTIVO de la sesión.
 *   2. Activar el SQLFilter.
 *   3. Inyectar el tenantId como parámetro del filtro.
 *
 * El id sale de la sesión (ActiveTenantSession), NO del usuario: desde
 * ADR-0015 el AppUser es global y puede pertenecer a varios negocios. La
 * clave solo se escribe en la sesión tras validar la membresía del
 * usuario (TenantSessionListener / SelectTenantController), y
 * RequireTenantListener impide llegar aquí sin ella.
 *
 * Si no hay tenant (login, /select-tenant, /onboarding, CLI), el filtro
 * queda HABILITADO con tenantId 0: no existe ningún tenant 0, así que
 * toda entidad con asociación `tenant` queda fuera. Es el default
 * seguro: sin negocio activo no se lee ni se escribe un solo dato.
 *
 * EXCEPCIÓN super_admin (ADR-0016): si el usuario autenticado tiene
 * ROLE_SUPER_ADMIN, el filtro se DESHABILITA y TenantContext se limpia.
 * El super_admin ve y opera sobre todos los tenants por diseño (la
 * revisión de datos de todos los negocios es su función); no tiene ni
 * requiere tenant activo, así que el filtro "tenantId 0" lo dejaría sin
 * ver nada. Al deshabilitarlo, sus consultas (vistas /admin, fase
 * posterior) no llevan la cláusula del filtro.
 *
 * EXCEPCIÓN del impersonation (Fase 6.2, ADR-0019): con la marca de
 * impersonation activa, el super_admin SÍ corre el filtro normal, con el
 * tenant impersonado. Ese es TODO el mecanismo del impersonation: no se
 * cambia el usuario ni su token, se cambia el tenant que el filtro aplica
 * — así que ve exactamente los datos de ese negocio, y nada más. El
 * bypass de arriba solo aplica cuando NO se está impersonando.
 *
 * Para comandos CLI:
 *   - Si no quieren filtrado, no pasa nada: el filtro queda deshabilitado
 *     porque este listener solo corre en kernel.request.
 *   - Si quieren filtrado por tenant, deben habilitar el filtro manualmente
 *     y llamar a $filter->setParameter('tenantId', ...).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final class TenantFilterListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActiveTenantSession $activeTenantSession,
        private readonly ImpersonationSession $impersonationSession,
        private readonly TenantContext $tenantContext,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // super_admin (ADR-0016): sin filtro de tenant y sin contexto.
        // El firewall (prioridad 8) ya autenticó, así que el token está
        // disponible aquí (prioridad 5).
        //
        // Con impersonation activo (ADR-0019) NO se toma este camino: el
        // filtro se habilita más abajo con el tenant impersonado.
        if ($this->isSuperAdmin() && !$this->impersonationSession->isActive()) {
            $this->tenantContext->clear();

            $filters = $this->em->getFilters();

            if ($filters->isEnabled(TenantFilter::NAME)) {
                $filters->disable(TenantFilter::NAME);
            }

            return;
        }

        // Durante un impersonation el tenant a filtrar ES el impersonado,
        // no el de la sesión del usuario (que el super_admin ni siquiera
        // suele tener).
        $tenantId = $this->impersonationSession->getTenantId()
            ?? $this->activeTenantSession->getTenantId();

        if ($tenantId === null) {
            $this->tenantContext->clear();
        } else {
            $this->tenantContext->setTenantId($tenantId);
        }

        $filters = $this->em->getFilters();

        if (!$filters->isEnabled(TenantFilter::NAME)) {
            $filters->enable(TenantFilter::NAME);
        }

        $filter = $filters->getFilter(TenantFilter::NAME);

        $filter->setParameter(
            'tenantId',
            (string)($tenantId ?? 0),
            'integer'
        );
    }

    private function isSuperAdmin(): bool
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof AppUser && $user->isSuperAdmin();
    }
}
