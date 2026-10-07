<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Transición de estado inválida en la máquina de estados de la inversión.
 */
final class InvalidStateTransitionException extends DomainException
{
    public static function invalid(string $from, string $to): self
    {
        return new self(sprintf(
            'Transición de estado inválida: %s → %s.',
            $from,
            $to
        ));
    }

    public static function fromTerminal(string $current, string $attempted): self
    {
        return new self(sprintf(
            'No se puede transicionar desde estado terminal %s a %s.',
            $current,
            $attempted
        ));
    }
}