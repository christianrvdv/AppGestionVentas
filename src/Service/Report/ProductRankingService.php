<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\DTO\Report\ProductRankingFilters;
use App\Repository\Contract\ProductRepositoryInterface;

/**
 * Genera el ranking de productos más rentables.
 */
final class ProductRankingService
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * @return array{
     *   rows: array<int, array{id: int, name: string, unitsSold: int, totalRevenue: string, grossProfit: string, margin: string}>,
     *   filters: ProductRankingFilters,
     *   totals: array{totalUnits: int, totalRevenue: string, totalGrossProfit: string, avgMargin: string}
     * }
     */
    public function generate(int $tenantId, ProductRankingFilters $filters): array
    {
        $rows = $this->productRepository->findRankingDetails(
            $tenantId,
            $filters->from,
            $filters->to,
            $filters->limit,
            false
        );

        $totalUnits = 0;
        $totalRevenue = '0.00';
        $totalGrossProfit = '0.00';

        foreach ($rows as $row) {
            $totalUnits += $row['unitsSold'];
            $totalRevenue = bcadd($totalRevenue, $row['totalRevenue'], 2);
            $totalGrossProfit = bcadd($totalGrossProfit, $row['grossProfit'], 2);
        }

        $avgMargin = '0.00';
        if (bccomp($totalRevenue, '0.00', 2) !== 0) {
            $avgMargin = bcdiv(bcmul($totalGrossProfit, '100.00', 2), $totalRevenue, 2);
        }

        return [
            'rows' => $rows,
            'filters' => $filters,
            'totals' => [
                'totalUnits' => $totalUnits,
                'totalRevenue' => $totalRevenue,
                'totalGrossProfit' => $totalGrossProfit,
                'avgMargin' => $avgMargin,
            ],
        ];
    }
}