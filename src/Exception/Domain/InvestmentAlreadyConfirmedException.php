<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Intento de modificar una inversión ya confirmada (agregar ítems, gastos, etc.).
 */
final class InvestmentAlreadyConfirmedException extends DomainException
{
    public static function forInvestment(int $investmentId): self
    {
        return new self(sprintf(
            'La inversión %d ya está confirmada. No se puede modificar.',
            $investmentId
        ));
    }
}