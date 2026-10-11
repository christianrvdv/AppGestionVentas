<?php

declare(strict_types=1);

namespace App\Tests\Integration\Report;

use App\DTO\Report\ProductRankingFilters;
use App\Entity\Investment;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Report\ProductRankingService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Smoke test para el ranking de productos.
 *
 * Verifica que el service genera datos correctamente con filtros.
 */
final class ProductRankingTest extends IntegrationTestCase
{
    private ProductRankingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = static::getContainer()->get(ProductRankingService::class);
    }

    private function createInvestmentWithSales(
        \App\Entity\Tenant $tenant,
        \App\Entity\AppUser $user,
        string $code,
        array $salesData // [['productName' => '', 'quantity' => 1, 'price' => 100.00, 'cost' => 50.00], ...]
    ): array {
        $investment = $this->createInvestment($tenant, $user, $code);
        $products = [];
        $items = [];

        foreach ($salesData as $index => $data) {
            $product = $this->createProduct($tenant, $data['productName']);
            $products[] = $product;

            $item = static::getContainer()->get(InvestmentItemService::class)
                ->addItem($investment, $product, $data['quantity'] * 2, number_format($data['cost'], 2, '.', ''));
            $items[] = $item;
        }
        $this->em->flush();

        $confirmation = static::getContainer()->get(InvestmentConfirmationService::class);
        $confirmation->confirm($investment);
        $this->em->flush();

        $saleService = static::getContainer()->get(\App\Service\Sale\SaleService::class);
        foreach ($salesData as $index => $data) {
            $saleService->register(
                new \App\DTO\Command\RegisterSaleCommand(
                    investmentId: $investment->getId(),
                    customerId: null,
                    date: new \DateTimeImmutable('2026-01-15'),
                    lines: [
                        new \App\DTO\Command\RegisterSaleLineCommand($items[$index]->getId(), $data['quantity'], number_format($data['price'], 2, '.', '')),
                    ]
                ),
                $user
            );
        }
        $this->em->flush();

        return [$investment, $products, $items];
    }

    public function test_genera_ranking_productos_con_datos_basicos(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        // Producto A: 5 unidades a 100.00, costo 60.00 -> ganancia 40.00/unidad = 200.00 total
        // Producto B: 3 unidades a 200.00, costo 150.00 -> ganancia 50.00/unidad = 150.00 total
        // Producto C: 10 unidades a 50.00, costo 30.00 -> ganancia 20.00/unidad = 200.00 total
        $this->createInvestmentWithSales($tenant, $user, 'INV-RANK', [
            ['productName' => 'Producto A', 'quantity' => 5, 'price' => 100.00, 'cost' => 60.00],
            ['productName' => 'Producto B', 'quantity' => 3, 'price' => 200.00, 'cost' => 150.00],
            ['productName' => 'Producto C', 'quantity' => 10, 'price' => 50.00, 'cost' => 30.00],
        ]);

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');
        $filters = new ProductRankingFilters($from, $to, 10);

        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertArrayHasKey('rows', $data);
        $this->assertCount(3, $data['rows']);

        // Verificar orden por ganancia bruta (desc): A=200, C=200, B=150
        // Empate entre A y C (ambos 200), el orden depende de la BD
        $profits = array_column($data['rows'], 'grossProfit');
        $this->assertEquals(['200.00', '200.00', '150.00'], $profits);

        // Verificar totales
        $this->assertEquals(18, $data['totals']['totalUnits']);
        $this->assertEquals('1600.00', $data['totals']['totalRevenue']);
        $this->assertEquals('550.00', $data['totals']['totalGrossProfit']);
    }

    public function test_filtro_limite_top_2(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->createInvestmentWithSales($tenant, $user, 'INV-LIMIT', [
            ['productName' => 'Producto Top1', 'quantity' => 10, 'price' => 100.00, 'cost' => 50.00],
            ['productName' => 'Producto Top2', 'quantity' => 5, 'price' => 100.00, 'cost' => 60.00],
            ['productName' => 'Producto Top3', 'quantity' => 2, 'price' => 100.00, 'cost' => 70.00],
        ]);

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');
        $filters = new ProductRankingFilters($from, $to, 2);

        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertCount(2, $data['rows']);
        $this->assertEquals('Producto Top1', $data['rows'][0]['name']);
        $this->assertEquals('Producto Top2', $data['rows'][1]['name']);
    }

    public function test_margen_calculado_correctamente(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        // Precio 100, costo 60 -> margen 40%
        $this->createInvestmentWithSales($tenant, $user, 'INV-MARGIN', [
            ['productName' => 'Producto Margen', 'quantity' => 10, 'price' => 100.00, 'cost' => 60.00],
        ]);

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');
        $filters = new ProductRankingFilters($from, $to, 10);

        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertCount(1, $data['rows']);
        $this->assertEquals('40.00', $data['rows'][0]['margin']);
        $this->assertEquals('40.00', $data['totals']['avgMargin']);
    }
}