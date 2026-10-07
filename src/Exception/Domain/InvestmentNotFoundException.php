<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Inversión no encontrada para el tenant actual.
 */
final class InvestmentNotFoundException extends DomainException
{
    public function __construct(int $investmentId)
    {
        parent::__construct(sprintf('Inversión %d no encontrada.', $investmentId));
    }
}