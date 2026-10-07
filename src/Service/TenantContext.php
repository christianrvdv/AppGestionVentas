<?php

declare(strict_types=1);

namespace App\Service;

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
 */
final class TenantContext
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
}
