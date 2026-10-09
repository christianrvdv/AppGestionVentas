<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Clave de sesión del tenant activo (ADR-0015).
 *
 * Es la ÚNICA fuente de verdad del negocio de una request web: se escribe
 * al hacer login (si el usuario tiene exactamente una membresía), al
 * elegirlo en /select-tenant y al crear un negocio en /onboarding, y se
 * borra al hacer logout o al loguear sin membresías.
 *
 * TenantContext se puebla a partir de esta clave (TenantFilterListener),
 * y RequireTenantListener obliga a elegir negocio cuando no existe.
 *
 * La sesión vive en el servidor: el valor NUNCA viene del request. Aun
 * así, el POST de /select-tenant valida además que la membresía
 * pertenezca al usuario autenticado antes de escribir la clave.
 */
final class ActiveTenantSession
{
    public const KEY = 'active_tenant_id';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getTenantId(): ?int
    {
        $value = $this->session()?->get(self::KEY);

        // Defensa: en la clave solo puede vivir un int positivo. Cualquier
        // otra cosa (basura, un string, un 0) se trata como "sin negocio".
        return \is_int($value) && $value > 0 ? $value : null;
    }

    public function setTenantId(int $tenantId): void
    {
        $session = $this->session();

        if ($session === null) {
            throw new \LogicException('No hay sesión disponible para activar un negocio.');
        }

        $session->set(self::KEY, $tenantId);
    }

    public function clear(): void
    {
        $this->session()?->remove(self::KEY);
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
