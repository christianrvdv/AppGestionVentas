<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Contexto del tenant activo para la petición actual.
 *
 * Es la única fuente de verdad consultada por:
 *   - TenantFilterListener (para configurar el SQLFilter).
 *   - Cualquier servicio que necesite el tenant sin depender del
 *     Security component.
 *
 * En web, lo puebla TenantFilterListener a partir del AppUser autenticado.
 * En CLI, un comando que necesite operar sobre un tenant debe asignarlo
 * explícitamente antes de ejecutar consultas.
 *
 * [FIX-6] WORKERS PERSISTENTES (RoadRunner / FrankenPHP / Swoole): los
 * servicios son singletons por worker y sobreviven entre requests. Si
 * TenantFilterListener no llega a correr en una request (error previo,
 * sub-request, bypass), el contexto conservaría el tenantId del request
 * anterior y CONTAMINARÍA el siguiente. Por eso esta clase implementa
 * ResetInterface: el autoconfigure la etiqueta con kernel.reset y
 * ServicesResetter invoca reset() al terminar cada request, limpiando
 * el tenant. En workers persistentes ese reset es OBLIGATORIO.
 *
 * Verificado con: bin/console debug:container --tag=kernel.reset
 */
final class TenantContext implements ResetInterface
{
    private ?int $tenantId = null;

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function clear(): void
    {
        $this->tenantId = null;
    }

    /**
     * [FIX-6] Reset del contrato Symfony (kernel.reset).
     * Deja el contexto vacío entre requests de un worker persistente.
     */
    public function reset(): void
    {
        $this->clear();
    }
}
