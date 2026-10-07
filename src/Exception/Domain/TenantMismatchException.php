<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Entidad relacionada pertenece a un tenant distinto.
 */
final class TenantMismatchException extends DomainException
{
    public static function forEntities(string $entityType, int $expectedTenantId, int $actualTenantId): self
    {
        return new self(sprintf(
            '%s (tenant %d) no pertenece al tenant actual (%d).',
            $entityType,
            $actualTenantId,
            $expectedTenantId
        ));
    }
}