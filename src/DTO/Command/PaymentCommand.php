<?php

declare(strict_types=1);

namespace App\DTO\Command;

/**
 * Comando para registrar un pago.
 *
 * Inmutable: todas las propiedades son readonly.
 * El pago puede estar vinculado a una venta (saleId) o ser pago a cuenta (sin saleId).
 *
 * [FIX-7] Decisión: ESTRATEGIA B — el pago NO siempre requiere cliente.
 *   customerId es nullable a propósito: modela el pago de mostrador (venta
 *   sin cliente registrado), comportamiento que el dominio ya contempla en
 *   Sale.customer. PaymentService::register omite la búsqueda de cliente
 *   cuando es null y Payment.customer quedó nullable en el mapping.
 *   Alternativa descartada (Estrategia A: customerId obligatorio) porque
 *   habría roto el flujo de mostrador y obligado a inventar un cliente.
 */
final readonly class PaymentCommand
{
    public function __construct(
        public int $investmentId,
        public ?int $customerId,
        public ?int $saleId,
        public string $amount,
        public \DateTimeImmutable $date,
        public string $method,           // ej: 'CASH', 'TRANSFER', 'CARD', 'OTHER'
        public ?string $reference = null, // nro. transacción, cheque, etc.
        public ?string $notes = null
    ) {
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $this->amount)) {
            throw new \InvalidArgumentException('amount debe ser numérico válido');
        }
        if (bccomp($this->amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('amount debe ser > 0');
        }
        if ($this->method === '') {
            throw new \InvalidArgumentException('method no puede estar vacío');
        }
    }
}