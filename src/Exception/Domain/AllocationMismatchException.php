<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * La suma de allocations de un gasto no cuadra con el monto del gasto.
 */
final class AllocationMismatchException extends DomainException
{
    public static function forExpense(int $expenseId, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Prorrateo inconsistente en gasto %d: suma allocations = %s, monto gasto = %s.',
            $expenseId,
            $actual,
            $expected
        ));
    }
}