<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Inversión no encontrada para el tenant actual.
 */
final class InvestmentNotFoundException extends DomainException
{
    public static function forInvestment(int $investmentId): self
    {
        return new self(sprintf('Inversión %d no encontrada.', $investmentId));
    }
}
