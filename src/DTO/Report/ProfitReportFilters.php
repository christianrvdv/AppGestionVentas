<?php

declare(strict_types=1);

namespace App\DTO\Report;

/**
 * Filtros para el reporte de ganancias por período.
 *
 * Inmutable: todas las propiedades son readonly.
 */
final readonly class ProfitReportFilters
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $granularity = 'day', // 'day' | 'week' | 'month'
        public ?int $investmentId = null,
        public ?int $customerId = null,
    ) {
        if (!\in_array($this->granularity, ['day', 'week', 'month'], true)) {
            throw new \InvalidArgumentException('Granularidad inválida: use day, week o month');
        }
        if ($this->from > $this->to) {
            throw new \InvalidArgumentException('La fecha "desde" no puede ser posterior a "hasta"');
        }
    }
}