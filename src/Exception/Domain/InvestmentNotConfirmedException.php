<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Operación requiere inversión confirmada, pero está en borrador (OPEN).
 */
final class InvestmentNotConfirmedException extends DomainException
{
    public static function forInvestment(int $investmentId): self
    {
        return new self(sprintf(
            'La inversión %d no está confirmada. Confírmela antes de continuar.',
            $investmentId
        ));
    }
}