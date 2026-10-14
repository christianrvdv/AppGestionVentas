<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AppUser;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Security\ActiveTenantSession;
use App\Security\ImpersonationSession;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Activa (o niega) el negocio en la sesión tras cada login y lo limpia
 * al salir (ADR-0015).
 *
 * LoginSuccessEvent es el único punto donde el usuario ya está
 * autenticado pero todavía NO se ha decidido a qué negocio pertenece la
 * request: FormLoginAuthenticator ya construyó su respuesta de éxito
 * (redirect a default_target_path) y este listener la puede
 * sobreescribir con setResponse(), que AuthenticatorManager respeta
 * antes de devolverla al firewall.
 *
 * Tres casos, con la sesión SIEMPRE limpiada antes de decidir:
 *   - una sola membresía  → se activa y se sigue al destino normal (/);
 *   - varias membresías   → /select-tenant;
 *   - ninguna membresía   → /onboarding.
 *
 * El clear() es obligatorio en los tres: migrate() del logout y el
 * cambio de cuenta en el mismo navegador conservan los atributos de la
 * sesión, así que sin limpiar, un segundo usuario del mismo navegador
 * heredaría el negocio del primero.
 *
 * El LogoutEvent solo limpia las claves: la sesión se invalida justo
 * después (SessionAuthenticationStrategy::onLogout).
 *
 * Fase 6.2 (ADR-0019): la marca de impersonation se limpia en los MISMOS
 * dos eventos. migrate() del logout y el cambio de cuenta en el mismo
 * navegador conservan los atributos de la sesión, así que sin limpiar,
 * una cuenta nueva heredaría el impersonation de la anterior — y con él,
 * el filtro de un negocio que ya no le corresponde ver.
 */
final class TenantSessionListener
{
    public function __construct(
        private readonly ActiveTenantSession $activeTenantSession,
        private readonly ImpersonationSession $impersonationSession,
        private readonly TenantMembershipRepositoryInterface $membershipRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof AppUser) {
            return;
        }

        // Limpia siempre: la sesión puede venir de un login previo de
        // otro usuario (o del remember_me de otro browser). Lo mismo vale
        // para el impersonation (ADR-0019).
        $this->activeTenantSession->clear();
        $this->impersonationSession->stop();

        // super_admin (ADR-0016): no requiere tenant activo ni
        // membresías. Se sigue al destino normal del form_login (/),
        // cuyo RequireTenantListener también lo deja pasar.
        if ($user->isSuperAdmin()) {
            return;
        }

        $memberships = $this->membershipRepository->findByUser($user);

        if ([] === $memberships) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_onboarding')));

            return;
        }

        if (1 === \count($memberships)) {
            $this->activeTenantSession->setTenantId($memberships[0]->getTenant()->getId());

            // No se toca la respuesta: el redirect del form_login (a /)
            // ya es el correcto, y / pasa por RequireTenantListener.
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_select_tenant')));
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $this->activeTenantSession->clear();
        $this->impersonationSession->stop();
    }
}
