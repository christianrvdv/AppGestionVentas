<?php

declare(strict_types=1);

namespace App\DTO\Report;

/**
 * Filtros para el ranking de productos más rentables.
 *
 * Inmutable: todas las propiedades son readonly.
 */
final readonly class ProductRankingFilters
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $limit = 10,
    ) {
        if ($this->limit < 1 || $this->limit > 100) {
            throw new \InvalidArgumentException('El límite debe estar entre 1 y 100');
        }
        if ($this->from > $this->to) {
            throw new \InvalidArgumentException('La fecha "desde" no puede ser posterior a "hasta"');
        }
    }
}