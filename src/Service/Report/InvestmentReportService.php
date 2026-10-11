<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\DTO\Report\InvestmentReportFilters;
use App\Entity\Investment;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;

/**
 * Genera el reporte de inversiones con estado de recuperación.
 *
 * Usa InvestmentSummaryRepository (ya tiene todos los campos calculados)
 * y InvestmentRepository para filtros por fecha/estado.
 */
final class InvestmentReportService
{
    public function __construct(
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly InvestmentSummaryRepositoryInterface $summaryRepository,
    ) {}

    /**
     * @return array{
     *   rows: array<int, array{
     *     investment: Investment,
     *     summary: \App\Entity\InvestmentSummary,
     *     recoveryPct: string,
     *     progressClass: string
     *   }>,
     *   filters: InvestmentReportFilters,
     *   statusLabels: array<string, string>,
     *   sortOptions: array{fields: array<string, string>, directions: array<string, string>}
     * }
     */
    public function generate(int $tenantId, InvestmentReportFilters $filters): array
    {
        $investments = $this->getFilteredInvestments($tenantId, $filters);

        $rows = [];
        foreach ($investments as $investment) {
            $summary = $this->summaryRepository->findByInvestment($investment->getId(), $tenantId);
            if ($summary === null) {
                continue;
            }

            $recoveryPct = $summary->getRecoveryPct();
            $progressClass = $this->getProgressClass($recoveryPct);

            $rows[] = [
                'investment' => $investment,
                'summary' => $summary,
                'recoveryPct' => $recoveryPct,
                'progressClass' => $progressClass,
            ];
        }

        usort($rows, function (array $a, array $b) use ($filters): int {
            $field = $filters->sortBy;
            $dir = $filters->sortDir === 'DESC' ? -1 : 1;

            $valueA = $a[$field] ?? $a['summary']->{'get' . ucfirst($field)}();
            $valueB = $b[$field] ?? $b['summary']->{'get' . ucfirst($field)}();

            // Para totalPending, usar el getter correcto
            if ($field === 'totalPending') {
                $valueA = $a['summary']->getTotalPending();
                $valueB = $b['summary']->getTotalPending();
            }

            return bccomp((string)$valueA, (string)$valueB, 2) * $dir;
        });

        return [
            'rows' => $rows,
            'filters' => $filters,
            'statusLabels' => $this->statusLabels(),
            'sortOptions' => [
                'fields' => [
                    'recoveryPct' => '% Recuperación',
                    'totalPending' => 'Pendiente',
                ],
                'directions' => [
                    'ASC' => 'Ascendente',
                    'DESC' => 'Descendente',
                ],
            ],
        ];
    }

    /**
     * @return Investment[]
     */
    private function getFilteredInvestments(int $tenantId, InvestmentReportFilters $filters): array
    {
        if ($filters->status !== null) {
            $investments = $this->investmentRepository->findByStatus($tenantId, $filters->status);
        } elseif ($filters->from !== null && $filters->to !== null) {
            $investments = $this->investmentRepository->findByDateRange($tenantId, $filters->from, $filters->to);
        } else {
            $investments = $this->investmentRepository->findAllByTenant($tenantId);
        }

        return $investments;
    }

    private function getProgressClass(string $recoveryPct): string
    {
        $pct = (float)$recoveryPct;
        if ($pct >= 100) {
            return 'bg-success';
        }
        if ($pct >= 75) {
            return 'bg-primary';
        }
        if ($pct >= 50) {
            return 'bg-warning';
        }
        if ($pct >= 25) {
            return 'bg-orange';
        }
        return 'bg-danger';
    }

    /**
     * @return array<string, string>
     */
    private function statusLabels(): array
    {
        return [
            Investment::STATUS_OPEN => 'Abierta',
            Investment::STATUS_PARTIAL => 'Parcial',
            Investment::STATUS_RECOVERED => 'Recuperada',
            Investment::STATUS_CLOSED => 'Cerrada',
            Investment::STATUS_CANCELLED => 'Cancelada',
        ];
    }
}