<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Ítem de inversión no pertenece a la inversión indicada.
 *
 * Los parámetros van en el orden en que aparecen en el mensaje (patrón
 * de CustomerMismatchException): primero la inversión a la que pertenece
 * el ítem, después la esperada.
 */
final class ItemNotInInvestmentException extends DomainException
{
    public static function forItemInvestment(int $itemId, int $actualInvestmentId, int $expectedInvestmentId): self
    {
        return new self(sprintf(
            'Ítem %d pertenece a inversión %d, no a %d.',
            $itemId,
            $actualInvestmentId,
            $expectedInvestmentId
        ));
    }
}
