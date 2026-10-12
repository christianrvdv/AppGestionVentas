<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AppUser;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Security\ActiveTenantSession;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Obliga a elegir un negocio antes de usar la app (ADR-0015).
 *
 * Con el login global, tener sesión no implica tener tenant activo: un
 * usuario recién logueado con varias membresías llega a cualquier ruta
 * sin el `active_tenant_id` en la sesión. Si no se interceptara, la
 * petición seguiría hacia el controller con TenantContext vacío y
 * TenantFilter habilitado devolvería listas vacías en vez de un error
 * claro. En su lugar se redirige:
 *
 *   - sin membresías → /onboarding (crear su primer negocio);
 *   - con una o más   → /select-tenant (elegir cuál usar).
 *
 * EXCEPCIÓN super_admin (ADR-0016): el rol global no requiere tenant
 * activo ni membresías. Sin este bypass, un super_admin sin membresías
 * quedaría atrapado en un loop de redirecciones (cualquier ruta no
 * blanqueada lo mandaría a /onboarding, que a su vez lo dejaría crear
 * un negocio que no necesita). Con él, el super_admin navega por / y
 * /admin con TenantContext limpio (TenantFilterListener se encarga del
 * filtro).
 *
 * Orden en kernel.request: el firewall corre a 8 (autentica y despacha
 * LoginSuccessEvent, que ya redirige con el tenant correcto), este
 * listener corre a 6 (después del firewall y de access_control, antes
 * del TenantFilterListener a 5, que solo debe correr con un tenant
 * conocido).
 *
 * Los caminos permitidos son de "antes de elegir negocio": login,
 * logout, el selector, el alta de negocio, el cambio de contraseña
 * propio (/cambiar-password), el panel de administración
 * (/admin, que exige ROLE_SUPER_ADMIN vía access_control y por diseño
 * no opera con un tenant concreto) y las herramientas del framework
 * (profiler, assets), que no tocan datos de negocio.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class RequireTenantListener
{
    private const ALLOWED_PATHS = [
        '/login',
        '/logout',
        '/onboarding',
        // Cambio de contraseña propio (Fase 6.1): debe poder usarse
        // con cualquier negocio activo o sin ninguno (el super_admin),
        // y es el destino de EnforcePasswordChangeListener.
        '/cambiar-password',
    ];

    /**
     * Prefijos (no rutas exactas) que no dependen de un negocio activo:
     * infraestructura del framework (profiler, assets), el selector de
     * negocio — que además tiene la subruta POST /select-tenant/{id}, si
     * solo se blanqueara la ruta exacta, el propio cambio de negocio
     * rebotaría contra este listener — y el panel /admin, de alcance
     * global (ADR-0016).
     */
    private const ALLOWED_PREFIXES = [
        '/_profiler',
        '/_wdt',
        '/_error',
        '/assets',
        '/build',
        '/select-tenant',
        '/admin',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ActiveTenantSession $activeTenantSession,
        private readonly TenantMembershipRepositoryInterface $membershipRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if ($this->isAllowed($path)) {
            return;
        }

        // Sin usuario autenticado no hay nada que exigir: access_control
        // (ROLE_USER) y el ExceptionListener del firewall se encargan de
        // redirigir a /login antes de llegar a cualquier controller.
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof AppUser) {
            return;
        }

        // super_admin (ADR-0016): el rol global no opera con un negocio
        // concreto, así que nunca se le exige tenant activo.
        if ($user->isSuperAdmin()) {
            return;
        }

        if ($this->activeTenantSession->getTenantId() !== null) {
            return;
        }

        $target = [] === $this->membershipRepository->findByUser($user)
            ? 'app_onboarding'
            : 'app_select_tenant';

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate($target)));
    }

    private function isAllowed(string $path): bool
    {
        if (\in_array($path, self::ALLOWED_PATHS, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
