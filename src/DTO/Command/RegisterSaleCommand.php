<?php

declare(strict_types=1);

namespace App\DTO\Command;

/**
 * Comando para registrar una venta completa.
 *
 * Inmutable: todas las propiedades son readonly.
 * Validación básica en constructor; validación de negocio en SaleService.
 */
final readonly class RegisterSaleCommand
{
    /** @var RegisterSaleLineCommand[] */
    public array $lines;

    public function __construct(
        public int $investmentId,
        public ?int $customerId,
        public \DateTimeImmutable $date,
        array $lines,
        public ?string $notes = null
    ) {
        if (empty($lines)) {
            throw new \InvalidArgumentException('Una venta debe tener al menos una línea');
        }
        $this->lines = $lines;
    }
}