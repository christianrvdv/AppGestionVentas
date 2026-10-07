<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Moneda de la operación no coincide con la moneda base de la inversión.
 */
final class CurrencyMismatchException extends DomainException
{
    public static function expectedVsActual(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Moneda esperada %s, recibida %s.',
            $expected,
            $actual
        ));
    }
}