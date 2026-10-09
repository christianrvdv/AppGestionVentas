<?php

declare(strict_types=1);

namespace App\Doctrine\EventListener;

use App\Doctrine\Filter\TenantFilter;
use App\Security\ActiveTenantSession;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenantId = $this->activeTenantSession->getTenantId();

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
}
