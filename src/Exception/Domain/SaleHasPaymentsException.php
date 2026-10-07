<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Intento de anular una venta que tiene pagos aplicados.
 */
final class SaleHasPaymentsException extends DomainException
{
    public static function forSale(int $saleId): self
    {
        return new self(sprintf(
            'La venta %d tiene pagos aplicados. Anule los pagos antes de anular la venta.',
            $saleId
        ));
    }
}