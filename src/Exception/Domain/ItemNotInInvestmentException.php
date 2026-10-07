<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Ítem de inversión no pertenece a la inversión indicada.
 */
final class ItemNotInInvestmentException extends DomainException
{
    public static function forItemInvestment(int $itemId, int $expectedInvestmentId, int $actualInvestmentId): self
    {
        return new self(sprintf(
            'Ítem %d pertenece a inversión %d, no a %d.',
            $itemId,
            $actualInvestmentId,
            $expectedInvestmentId
        ));
    }
}