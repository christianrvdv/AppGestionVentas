<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\DTO\Report\ProfitReportFilters;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;

/**
 * Genera el reporte de ganancias por período (día/semana/mes).
 *
 * Agrupa los totales diarios en PHP para evitar SQL complejo y
 * reutilizar SaleRepository::dailyTotals() que ya existe.
 */
final class ProfitReportService
{
    public function __construct(
        private readonly SaleRepositoryInterface $saleRepository,
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
    ) {}

    /**
     * @return array{
     *   rows: array<int, array{period: string, sales: string, grossProfit: string, recognizedProfit: string}>,
     *   totals: array{totalSales: string, totalGrossProfit: string, totalRecognizedProfit: string},
     *   filters: ProfitReportFilters,
     *   investments: array<int, \App\Entity\Investment>,
     *   customers: array<int, \App\Entity\Customer>,
     *   granularityLabels: array{day: string, week: string, month: string}
     * }
     */
    public function generate(int $tenantId, ProfitReportFilters $filters): array
    {
        $daily = $this->saleRepository->dailyTotalsWithFilters(
            $tenantId,
            $filters->from,
            $filters->to,
            $filters->investmentId,
            $filters->customerId,
            false
        );

        $grouped = $this->groupByGranularity($daily, $filters->granularity);

        $totalSales = '0.00';
        $totalGrossProfit = '0.00';
        $totalRecognizedProfit = '0.00';

        $rows = [];
        foreach ($grouped as $period => $data) {
            $totalSales = bcadd($totalSales, $data['total'], 2);
            $totalGrossProfit = bcadd($totalGrossProfit, $data['profit'], 2);

            $recognized = $this->saleRepository->sumRecognizedProfitByPeriod(
                $tenantId,
                \DateTimeImmutable::createFromFormat('Y-m-d', $data['from']),
                \DateTimeImmutable::createFromFormat('Y-m-d', $data['to'])
            );
            $totalRecognizedProfit = bcadd($totalRecognizedProfit, $recognized, 2);

            $rows[] = [
                'period' => $period,
                'sales' => $data['total'],
                'grossProfit' => $data['profit'],
                'recognizedProfit' => $recognized,
            ];
        }

        $investments = $this->investmentRepository->findAllByTenant($tenantId);
        $customers = $this->customerRepository->findActiveByTenant($tenantId);

        return [
            'rows' => $rows,
            'totals' => [
                'totalSales' => $totalSales,
                'totalGrossProfit' => $totalGrossProfit,
                'totalRecognizedProfit' => $totalRecognizedProfit,
            ],
            'filters' => $filters,
            'investments' => $investments,
            'customers' => $customers,
            'granularityLabels' => [
                'day' => 'Día',
                'week' => 'Semana',
                'month' => 'Mes',
            ],
        ];
    }

    /**
     * Agrupa los totales diarios por semana o mes.
     *
     * @param array<int, array{date: string, total: string, profit: string}> $daily
     * @return array<string, array{from: string, to: string, total: string, profit: string}>
     */
    private function groupByGranularity(array $daily, string $granularity): array
    {
        if ($granularity === 'day') {
            $result = [];
            foreach ($daily as $row) {
                $result[$row['date']] = [
                    'from' => $row['date'],
                    'to' => $row['date'],
                    'total' => $row['total'],
                    'profit' => $row['profit'],
                ];
            }
            return $result;
        }

        $grouped = [];
        foreach ($daily as $row) {
            $date = new \DateTimeImmutable($row['date']);
            $key = match ($granularity) {
                'week' => $date->format('Y-\WW'), // ISO week number
                'month' => $date->format('Y-m'),
                default => $row['date'],
            };

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'from' => $row['date'],
                    'to' => $row['date'],
                    'total' => '0.00',
                    'profit' => '0.00',
                ];
            }
            $grouped[$key]['to'] = $row['date'];
            $grouped[$key]['total'] = bcadd($grouped[$key]['total'], $row['total'], 2);
            $grouped[$key]['profit'] = bcadd($grouped[$key]['profit'], $row['profit'], 2);
        }

        ksort($grouped);

        return $grouped;
    }
}