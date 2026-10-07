<?php

declare(strict_types=1);

namespace App\DTO\Command;

/**
 * Línea de comando para registrar una venta.
 *
 * Inmutable: todas las propiedades son readonly.
 * Validación básica en constructor; validación de negocio en SaleService.
 */
final readonly class RegisterSaleLineCommand
{
    public function __construct(
        public int $investmentItemId,
        public int $quantity,
        public string $unitPrice
    ) {
        if ($this->quantity <= 0) {
            throw new \InvalidArgumentException('quantity debe ser > 0');
        }
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $this->unitPrice)) {
            throw new \InvalidArgumentException('unitPrice debe ser numérico válido');
        }
        if (bccomp($this->unitPrice, '0', 2) < 0) {
            throw new \InvalidArgumentException('unitPrice no puede ser negativo');
        }
    }
}