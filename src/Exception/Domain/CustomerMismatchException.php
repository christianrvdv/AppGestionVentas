<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Cliente del pago no coincide con el cliente de la venta.
 */
final class CustomerMismatchException extends DomainException
{
    public static function forSale(int $saleId, int $expectedCustomerId, int $actualCustomerId): self
    {
        return new self(sprintf(
            'Cliente de la venta %d (esperado: %d, recibido: %d) no coincide.',
            $saleId,
            $expectedCustomerId,
            $actualCustomerId
        ));
    }
}