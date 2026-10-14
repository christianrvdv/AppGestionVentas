<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Claims de impersonation de TENANT en la sesión (Fase 6.2, ADR-0019).
 *
 * El impersonation es de negocio, no de usuario: el super_admin conserva
 * su identidad, su token y su rol, y lo único que cambia es el tenant
 * activo (ActiveTenantSession). Estos claims son la marca de que ese
 * cambio fue deliberado y quién lo hizo:
 *
 *   - impersonating_tenant_id        negocio que se está viendo;
 *   - impersonating_started_at       cuándo empezó (unix timestamp);
 *   - impersonating_by_user_id       qué super_admin entró;
 *   - impersonating_log_id           fila de impersonation_log a cerrar
 *                                    al salir (la auditoría no puede
 *                                    depender de buscar "la abierta");
 *   - impersonating_previous_tenant_id tenant que tenía antes, para
 *                                    restaurarlo al salir.
 *
 * Quien lee la marca (TenantFilterListener, el banner de base.html.twig)
 * decide en base a ella; por eso es servidor-solo: el valor NUNCA viene
 * del request, igual que active_tenant_id (ADR-0015).
 *
 * Limpieza: stop() la borra entera, y TenantSessionListener la borra en
 * LoginSuccessEvent y LogoutEvent — migrate() conserva los atributos de
 * la sesión, así que una cuenta nueva del mismo navegador no debe
 * heredar un impersonation ajeno.
 */
final class ImpersonationSession
{
    public const KEY_TENANT_ID = 'impersonating_tenant_id';
    public const KEY_STARTED_AT = 'impersonating_started_at';
    public const KEY_BY_USER_ID = 'impersonating_by_user_id';
    public const KEY_LOG_ID = 'impersonating_log_id';
    public const KEY_PREVIOUS_TENANT_ID = 'impersonating_previous_tenant_id';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function start(int $tenantId, int $userId, int $logId, ?int $previousTenantId): void
    {
        $session = $this->session();

        if ($session === null) {
            throw new \LogicException('No hay sesión disponible para iniciar un impersonation.');
        }

        $session->set(self::KEY_TENANT_ID, $tenantId);
        $session->set(self::KEY_STARTED_AT, time());
        $session->set(self::KEY_BY_USER_ID, $userId);
        $session->set(self::KEY_LOG_ID, $logId);
        $session->set(self::KEY_PREVIOUS_TENANT_ID, $previousTenantId);
    }

    public function stop(): void
    {
        $session = $this->session();

        if ($session === null) {
            return;
        }

        $session->remove(self::KEY_TENANT_ID);
        $session->remove(self::KEY_STARTED_AT);
        $session->remove(self::KEY_BY_USER_ID);
        $session->remove(self::KEY_LOG_ID);
        $session->remove(self::KEY_PREVIOUS_TENANT_ID);
    }

    /**
     * La marca solo cuenta si trae un tenant válido: un 0, un string o
     * cualquier basura se trata como "sin impersonation" (misma defensa
     * que ActiveTenantSession::getTenantId).
     */
    public function isActive(): bool
    {
        return $this->getTenantId() !== null;
    }

    public function getTenantId(): ?int
    {
        return $this->positiveInt(self::KEY_TENANT_ID);
    }

    public function getByUserId(): ?int
    {
        return $this->positiveInt(self::KEY_BY_USER_ID);
    }

    public function getLogId(): ?int
    {
        return $this->positiveInt(self::KEY_LOG_ID);
    }

    /**
     * Tenant que tenía la sesión antes de entrar (null = no tenía).
     */
    public function getPreviousTenantId(): ?int
    {
        return $this->positiveInt(self::KEY_PREVIOUS_TENANT_ID);
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        $timestamp = $this->positiveInt(self::KEY_STARTED_AT);

        if ($timestamp === null) {
            return null;
        }

        // '@ts' fija la zona a UTC (formato del literal); se lleva a la
        // zona por defecto de la app para que sea comparable con los
        // timestamps de las entidades.
        return (new \DateTimeImmutable('@'.$timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function positiveInt(string $key): ?int
    {
        $value = $this->session()?->get($key);

        return \is_int($value) && $value > 0 ? $value : null;
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
