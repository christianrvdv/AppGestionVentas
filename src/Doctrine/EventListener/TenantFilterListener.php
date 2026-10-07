<?php

declare(strict_types=1);

namespace App\Doctrine\EventListener;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\AppUser;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Habilita y configura el TenantFilter al inicio de cada request HTTP.
 *
 * Debe ejecutarse DESPUÉS del firewall de Symfony (que corre a
 * prioridad 8). Usamos prioridad 5.
 *
 * Responsabilidades:
 *   1. Poblar TenantContext con el tenant del AppUser autenticado.
 *   2. Activar el SQLFilter.
 *   3. Inyectar el tenantId como parámetro del filtro.
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
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if ($user instanceof AppUser) {
            $this->tenantContext->setTenantId($user->getTenant()->getId());
        } else {
            $this->tenantContext->clear();
        }

        $filters = $this->em->getFilters();

        if (!$filters->isEnabled(TenantFilter::NAME)) {
            $filters->enable(TenantFilter::NAME);
        }

        $filter = $filters->getFilter(TenantFilter::NAME);

        $filter->setParameter(
            'tenantId',
            (string)($this->tenantContext->getTenantId() ?? 0),
            'integer'
        );
    }
}
