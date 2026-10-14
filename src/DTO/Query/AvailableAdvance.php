<?php

declare(strict_types=1);

namespace App\DTO\Query;

use App\Entity\Payment;

/**
 * Anticipo de un cliente con saldo todavía por aplicar (Fase 3f, ADR-0018).
 *
 * `available` es amount − Σ allocations: lo que queda del pago una vez
 * descontado lo que ya cubre otras ventas. Es el número que ve el usuario
 * en el selector "Aplicar anticipo" y el que PaymentService::applyToSale
 * valida contra el monto pedido.
 */
final readonly class AvailableAdvance
{
    public function __construct(
        public Payment $payment,
        public string $available,
    ) {
    }
}
