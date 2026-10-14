<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Una aplicación de anticipo/pago a una venta viola una regla de dominio
 * (Fase 3f, ADR-0018).
 *
 * Son errores de negocio, no de programación: la UI los recoge y los
 * muestra como flash, igual que el resto de DomainException.
 */
final class PaymentAllocationException extends DomainException
{
    public static function nonPositiveAmount(): self
    {
        return new self('El monto a aplicar debe ser un importe mayor que cero.');
    }

    public static function exceedsPaymentAvailable(string $requested, string $available): self
    {
        return new self(sprintf(
            'El monto a aplicar (%s) supera el saldo disponible del pago (%s).',
            $requested,
            $available
        ));
    }

    public static function exceedsSalePending(string $requested, string $pending): self
    {
        return new self(sprintf(
            'El monto a aplicar (%s) supera el pendiente de la venta (%s).',
            $requested,
            $pending
        ));
    }

    public static function alreadyAppliedToSale(int $paymentId, int $saleId): self
    {
        return new self(sprintf(
            'El pago %d ya cubre la venta %d: cada anticipo se aplica una sola vez a cada venta.',
            $paymentId,
            $saleId
        ));
    }

    public static function voidedSale(int $saleId): self
    {
        return new self(sprintf('No se puede aplicar un anticipo a la venta anulada %d.', $saleId));
    }
}
