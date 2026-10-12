<?php

declare(strict_types=1);

namespace App\Tests\Integration\Sale;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Service\Investment\InvestmentItemService;
use App\Service\Sale\SaleService;
use App\Tests\Integration\IntegrationTestCase;
use App\Tests\Support\QueryCounter;

/**
 * Regresión de N+1 en SaleService::register (Fase 7.5).
 *
 * Antes del fix, cada línea de venta hacía su propia
 * findByIdAndTenant() contra investment_item (5 líneas = 5 queries).
 * Ahora los ítems se cargan UNA sola vez con findByIdsAndTenant() y el
 * loop trabaja en memoria; este test impide que la carga por línea
 * vuelva a entrar.
 */
final class SaleServiceQueryCountTest extends IntegrationTestCase
{
    public function test_venta_con_tres_lineas_hace_una_sola_query_de_items(): void
    {
        [, $user, $investment, $items] = $this->investmentWithThreeItems(10);

        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        static::getContainer()->get(SaleService::class)->register(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [
                    new RegisterSaleLineCommand($items[0]->getId(), 1, '150.00'),
                    new RegisterSaleLineCommand($items[1]->getId(), 2, '150.00'),
                    new RegisterSaleLineCommand($items[2]->getId(), 3, '150.00'),
                ]
            ),
            $user
        );

        $this->assertSame(
            1,
            $counter->countContaining('FROM investment_item'),
            "Se esperaba UNA sola query a investment_item (carga batch por findByIdsAndTenant); se encontraron:\n"
            .implode("\n", $counter->containing('FROM investment_item'))
        );
    }

    public function test_stock_se_precarga_en_una_sola_query_agregada(): void
    {
        [, $user, $investment, $items] = $this->investmentWithThreeItems(10);

        $counter = static::getContainer()->get(QueryCounter::class);
        $counter->reset();

        static::getContainer()->get(SaleService::class)->register(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [
                    new RegisterSaleLineCommand($items[0]->getId(), 1, '150.00'),
                    new RegisterSaleLineCommand($items[1]->getId(), 2, '150.00'),
                    new RegisterSaleLineCommand($items[2]->getId(), 3, '150.00'),
                ]
            ),
            $user
        );

        // getStockByItemIds agrupa por investment_item_id en UNA query.
        // Se excluye el INNER JOIN porque recompute() también consulta
        // inventory_movement con GROUP BY (getStockByInvestment), pero eso
        // es una sola query por inversión, no una por ítem.
        $perItemAggregates = array_values(array_filter(
            $counter->containing('FROM inventory_movement'),
            static fn (string $sql): bool => stripos($sql, 'SUM(') !== false
                && stripos($sql, 'INNER JOIN') === false
        ));

        $this->assertCount(
            1,
            $perItemAggregates,
            "Se esperaba UNA sola query agregada de stock (getStockByItemIds con GROUP BY); se encontraron:\n"
            .implode("\n", $perItemAggregates)
        );

        $this->assertStringContainsString(
            'GROUP BY',
            $perItemAggregates[0],
            'La query agregada de stock debe agrupar por investment_item_id.'
        );
    }

    /**
     * Inversión confirmada con 3 productos/ítems y stock de compra.
     *
     * @return array{0: Tenant, 1: AppUser, 2: Investment, 3: list<InvestmentItem>}
     */
    private function investmentWithThreeItems(int $quantity): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);

        $itemService = static::getContainer()->get(InvestmentItemService::class);
        $items = [];
        foreach ([1, 2, 3] as $n) {
            $product = $this->createProduct($tenant, 'Producto '.$n);
            $items[] = $itemService->addItem($investment, $product, $quantity, '100.00');
        }
        $this->em->flush();
        $this->confirmInvestment($investment);

        return [$tenant, $user, $investment, $items];
    }
}
