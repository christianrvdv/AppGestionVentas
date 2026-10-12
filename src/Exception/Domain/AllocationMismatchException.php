<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * La suma de allocations de un gasto no cuadra con el monto del gasto.
 *
 * Los parámetros van en el orden en que aparecen en el mensaje (patrón
 * de CustomerMismatchException): primero la suma real de allocations,
 * después el monto del gasto esperado.
 */
final class AllocationMismatchException extends DomainException
{
    public static function forExpense(int $expenseId, string $allocatedSum, string $expenseAmount): self
    {
        return new self(sprintf(
            'Prorrateo inconsistente en gasto %d: suma allocations = %s, monto gasto = %s.',
            $expenseId,
            $allocatedSum,
            $expenseAmount
        ));
    }
}
