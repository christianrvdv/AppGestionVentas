<?php

declare(strict_types=1);

namespace App\Tests\Integration\Report;

use App\DTO\Report\ProfitReportFilters;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Service\Investment\InvestmentItemService;
use App\Service\Report\ProfitReportService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Smoke test para el reporte de ganancias por período.
 *
 * Verifica que el service genera datos correctamente con filtros.
 */
final class ProfitReportTest extends IntegrationTestCase
{
    private ProfitReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = static::getContainer()->get(ProfitReportService::class);
    }

    /**
     * @return array{0: \App\Entity\Tenant, 1: \App\Entity\AppUser, 2: \App\Entity\Investment, 3: InvestmentItem}
     */
    private function investmentWithStock(int $quantity = 10, string $code = 'INV-TEST'): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $investment = $this->createInvestment($tenant, $user, $code);
        $product = $this->createProduct($tenant, 'Producto '.$code);

        $item = static::getContainer()->get(InvestmentItemService::class)
            ->addItem($investment, $product, $quantity, '100.00');
        $this->em->flush();
        $this->confirmInvestment($investment);

        return [$tenant, $user, $investment, $item];
    }

    public function test_genera_reporte_ganancias_con_datos_basicos(): void
    {
        [$tenant, $user, $investment, $item] = $this->investmentWithStock(10, 'INV-PROFIT');
        $product = $this->createProduct($tenant, 'Producto Test');
        $customer = $this->createCustomer($tenant, 'Cliente Test');

        $saleService = static::getContainer()->get(\App\Service\Sale\SaleService::class);
        $saleService->register(
            new \App\DTO\Command\RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: $customer->getId(),
                date: new \DateTimeImmutable('2026-01-15'),
                lines: [
                    new \App\DTO\Command\RegisterSaleLineCommand($item->getId(), 3, '150.00'),
                ]
            ),
            $user
        );
        $this->em->flush();

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');
        $filters = new ProfitReportFilters($from, $to, 'day', $investment->getId(), $customer->getId());

        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('filters', $data);

        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];

        $this->assertEquals('2026-01-15', $row['period']);
        $this->assertEquals('450.00', $row['sales']);
        $this->assertEquals('150.00', $row['grossProfit']);
        $this->assertEquals('150.00', $row['recognizedProfit']);

        $this->assertEquals('450.00', $data['totals']['totalSales']);
        $this->assertEquals('150.00', $data['totals']['totalGrossProfit']);
        $this->assertEquals('150.00', $data['totals']['totalRecognizedProfit']);
    }

    public function test_genera_reporte_ganancias_agrupado_por_semana(): void
    {
        [$tenant, $user, $investment, $item] = $this->investmentWithStock(20, 'INV-WEEK');

        $saleService = static::getContainer()->get(\App\Service\Sale\SaleService::class);
        $saleService->register(
            new \App\DTO\Command\RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-05'),
                lines: [new \App\DTO\Command\RegisterSaleLineCommand($item->getId(), 2, '100.00')]
            ),
            $user
        );
        $saleService->register(
            new \App\DTO\Command\RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-12'),
                lines: [new \App\DTO\Command\RegisterSaleLineCommand($item->getId(), 3, '100.00')]
            ),
            $user
        );
        $this->em->flush();

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');
        $filters = new ProfitReportFilters($from, $to, 'week');

        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertArrayHasKey('rows', $data);
        $this->assertGreaterThanOrEqual(1, count($data['rows']));

        $totalSales = '0.00';
        foreach ($data['rows'] as $row) {
            $totalSales = bcadd($totalSales, $row['sales'], 2);
        }
        $this->assertEquals('500.00', $totalSales);
    }

    public function test_filtro_por_cliente_funciona(): void
    {
        [$tenant, $user, $investment, $item] = $this->investmentWithStock(10, 'INV-CUST');
        $customer1 = $this->createCustomer($tenant, 'Cliente 1');
        $customer2 = $this->createCustomer($tenant, 'Cliente 2');

        $saleService = static::getContainer()->get(\App\Service\Sale\SaleService::class);
        $saleService->register(
            new \App\DTO\Command\RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: $customer1->getId(),
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [new \App\DTO\Command\RegisterSaleLineCommand($item->getId(), 2, '150.00')]
            ),
            $user
        );
        $saleService->register(
            new \App\DTO\Command\RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: $customer2->getId(),
                date: new \DateTimeImmutable('2026-01-11'),
                lines: [new \App\DTO\Command\RegisterSaleLineCommand($item->getId(), 3, '150.00')]
            ),
            $user
        );
        $this->em->flush();

        $from = new \DateTimeImmutable('2026-01-01');
        $to = new \DateTimeImmutable('2026-01-31');
        $filters = new ProfitReportFilters($from, $to, 'day', null, $customer1->getId());

        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertEquals('300.00', $data['totals']['totalSales']);
        $this->assertEquals('100.00', $data['totals']['totalGrossProfit']);
    }
}