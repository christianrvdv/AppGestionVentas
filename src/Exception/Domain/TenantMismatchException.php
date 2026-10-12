<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Entidad relacionada pertenece a un tenant distinto.
 *
 * Los parámetros van en el orden en que aparecen en el mensaje (patrón
 * de CustomerMismatchException): primero el tenant al que pertenece la
 * entidad, después el tenant esperado de la operación.
 */
final class TenantMismatchException extends DomainException
{
    public static function forEntities(string $entityType, int $actualTenantId, int $expectedTenantId): self
    {
        return new self(sprintf(
            '%s (tenant %d) no pertenece al tenant actual (%d).',
            $entityType,
            $actualTenantId,
            $expectedTenantId
        ));
    }
}
