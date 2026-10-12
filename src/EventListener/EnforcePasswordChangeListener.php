<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AppUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Fuerza el cambio de contraseña tras un reset del panel admin (Fase
 * 6.1, opción A).
 *
 * AppUser::mustChangePassword se activa al generar la contraseña
 * temporal (/admin/users/{id}/reset-password) y se apaga al completar
 * /cambiar-password. Mientras esté activo, el usuario queda "atrapado"
 * en el cambio de contraseña por DOS ganchos:
 *
 *   1. LoginSuccessEvent (prioridad -10): el POST del login se corta
 *      en el firewall — RequestEvent::setResponse() hace
 *      stopPropagation(), así que ningún listener de kernel.request
 *      posterior corre — por eso el redirect a /cambiar-password se
 *      fija acá, DESPUÉS de TenantSessionListener (prioridad 0), que
 *      es quien decide el destino normal del login. Corre después para
 *      que su /select-tenant (varias membresías) no pise este.
 *
 *   2. kernel.request (prioridad 7): el resto de la app re-bota a
 *      /cambiar-password en cada request. Corre después del firewall
 *      (8) — el token ya existe — y antes de RequireTenantListener (6),
 *      que además tiene /cambiar-password blanqueada, porque el cambio
 *      debe poder usarse con cualquier negocio activo o sin ninguno.
 *
 * El bloqueo NO cubre las rutas de recuperación (ver ALLOWED_PREFIXES):
 * el usuario debe poder completar el cambio (/cambiar-password),
 * salir (/logout), volver a entrar (/login), elegir o crear su negocio
 * (/select-tenant, /onboarding) y usar el panel global (/admin). Si
 * alguna de esas quedara bloqueada, el flag dejaría usuarios atrapados
 * sin salida (Fase 7).
 */
final class EnforcePasswordChangeListener
{
    /**
     * Prefijos que sí pueden usarse con el flag activo: el destino
     * mismo, salir de la sesión, volver a entrar, las rutas desde las
     * que el usuario se recupera o decide a qué negocio usar (Fase 7:
     * exenciones verificadas — sin ellas un usuario con varias
     * membresías o un super_admin sin tenant quedaba bloqueado en
     * /select-tenant y /admin pudiendo usar /cambiar-password), los
     * estáticos del framework (un redirect en /assets o /build rompería
     * la página entera con HTML donde debe haber CSS/JS) y las
     * herramientas del profiler.
     */
    private const ALLOWED_PREFIXES = [
        '/cambiar-password',
        '/logout',
        '/login',
        '/select-tenant',
        '/onboarding',
        '/admin',
        '/assets',
        '/build',
        '/_profiler',
        '/_wdt',
        '/_error',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class, priority: -10)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof AppUser || !$user->isMustChangePassword()) {
            return;
        }

        // Sobrescribe el destino normal del login (form_login o
        // /select-tenant de TenantSessionListener): la contraseña
        // temporal no debe usarse como si fuera la definitiva.
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_password_change')));
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof AppUser || !$user->isMustChangePassword()) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_password_change')));
    }
}
