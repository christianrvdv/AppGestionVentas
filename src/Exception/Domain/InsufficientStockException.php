<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Stock insuficiente para realizar la operación (venta, merma, etc.).
 */
final class InsufficientStockException extends DomainException
{
    public static function forItem(int $itemId, int $requested, int $available): self
    {
        return new self(sprintf(
            'Stock insuficiente para ítem %d: solicitado %d, disponible %d.',
            $itemId,
            $requested,
            $available
        ));
    }
}