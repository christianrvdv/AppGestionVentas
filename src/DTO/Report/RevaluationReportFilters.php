<?php

declare(strict_types=1);

namespace App\DTO\Report;

/**
 * Filtros para el reporte de impacto de revaluación.
 *
 * Inmutable: todas las propiedades son readonly.
 */
final readonly class RevaluationReportFilters
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
        if ($this->from !== null && $this->to !== null && $this->from > $this->to) {
            throw new \InvalidArgumentException('La fecha "desde" no puede ser posterior a "hasta"');
        }
    }
}