<?php

declare(strict_types=1);

namespace App\DTO\Report;

/**
 * Filtros para el reporte de inversiones.
 *
 * Inmutable: todas las propiedades son readonly.
 */
final readonly class InvestmentReportFilters
{
    /** @var list<string> */
    public const VALID_STATUSES = [
        \App\Entity\Investment::STATUS_OPEN,
        \App\Entity\Investment::STATUS_PARTIAL,
        \App\Entity\Investment::STATUS_RECOVERED,
        \App\Entity\Investment::STATUS_CLOSED,
        \App\Entity\Investment::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    public const VALID_SORT_FIELDS = ['recoveryPct', 'totalPending'];

    public readonly string $sortDir;

    public function __construct(
        public ?string $status = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public string $sortBy = 'recoveryPct',
        string $sortDir = 'DESC',
    ) {
        if ($this->status !== null && !\in_array($this->status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Estado de inversión inválido');
        }
        if (!\in_array($this->sortBy, self::VALID_SORT_FIELDS, true)) {
            throw new \InvalidArgumentException('Campo de ordenación inválido');
        }
        $normalizedSortDir = mb_strtoupper($sortDir);
        if (!\in_array($normalizedSortDir, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Dirección de ordenación inválida');
        }
        $this->sortDir = $normalizedSortDir;
        if ($this->from !== null && $this->to !== null && $this->from > $this->to) {
            throw new \InvalidArgumentException('La fecha "desde" no puede ser posterior a "hasta"');
        }
    }
}