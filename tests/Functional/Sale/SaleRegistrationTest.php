<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Entity\InventoryMovement;
use App\Entity\InvestmentSummary;
use App\Entity\Sale;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fase 3c.2: alta de una venta desde el navegador
 * (form -> SaleService::register).
 *
 * Los casos no se conforman con "no lanzó": verifican lo que quedó en BD
 * (total, movimientos, summary) porque Fase 3c.1 descubrió que el flujo
 * UI completo podía persistir una venta con ganancia reconocida en 0.00
 * por culpa de un derivado que se calculaba tarde.
 */
final class SaleRegistrationTest extends FunctionalTestCase
{
    public function testNewRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/ventas/nueva');

        $this->assertResponseRedirects('/login');
    }

    public function testNewRendersSellableInvestmentsAndOneLineRow(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user);
        // Inversión sin confirmar: no puede aparecer en el desplegable.
        $draft = $this->seedInvestment($user, new \DateTimeImmutable('2026-02-01'), 'Sin confirmar');
        $this->addItemToInvestment($draft, $this->seedProduct($draft->getTenant(), 'En borrador'));

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas/nueva');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(sprintf('select[name="sale_form[investment]"] option[value="%d"]', $investment->getId())));
        $this->assertCount(0, $crawler->filter(sprintf('select[name="sale_form[investment]"] option[value="%d"]', $draft->getId())));

        // El ítem de la inversión confirmada se ofrece; el del borrador no.
        $this->assertCount(1, $crawler->filter(sprintf('select[name="sale_form[lines][0][investmentItem]"] option[value="%d"]', $item->getId())));
        $this->assertCount(1, $crawler->filter('[data-sale-lines-target="rows"] [data-sale-links-row]'));
        $this->assertCount(1, $crawler->filter('template[data-sale-lines-target="prototype"]'));

        // Precio sugerido visible como referencia (costo 100.00 + 40%).
        $this->assertSame(
            '140.00',
            $crawler->filter(sprintf('select[name="sale_form[lines][0][investmentItem]"] option[value="%d"]', $item->getId()))->attr('data-suggested-price')
        );

        // Cliente opcional: hay opción vacía de "consumidor final".
        $this->assertCount(1, $crawler->filter('select[name="sale_form[customer]"] option[value=""]'));
    }

    public function testRegisterSaleWithOneLineUpdatesTotalStockAndSummary(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $this->loginAsDemoAdmin($client);
        $this->postSale($client, [
            'investment' => $investment->getId(),
            'customer' => $customer->getId(),
            'date' => '2026-01-15',
            'notes' => 'Venta del mostrador',
            'lines' => [[
                'investmentItem' => $item->getId(),
                'quantity' => '2',
                'unitPrice' => '150.00',
            ]],
        ]);

        $sale = $this->onlyPersistedSale();
        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', sprintf('Venta %d registrada.', $sale->getId()));

        $this->assertDecimalEquals('300.00', $sale->getTotalAmount(), 'totalAmount de la venta.');
        $this->assertSame($customer->getId(), $sale->getCustomer()?->getId(), 'cliente guardado.');
        $this->assertSame('2026-01-15', $sale->getSaleDate()->format('Y-m-d'), 'fecha elegida en el form.');

        $line = $sale->getLines()->first();
        $this->assertDecimalEquals('100.00', $line->getRealUnitCostSnapshot());
        $this->assertDecimalEquals('200.00', $line->getCostRecovered());
        $this->assertDecimalEquals('100.00', $line->getGrossProfitLine());
        $this->assertDecimalEquals('100.00', $line->getRecognizedProfitLine());

        $this->assertSame(8, $this->stockOf($item->getId(), $tenant->getId()), 'el stock bajó 2 unidades.');
        $this->assertSaleMovementsAre(-2, $item->getId(), $tenant->getId());

        $summary = $this->summaryOf($investment->getId(), $tenant->getId());
        $this->assertDecimalEquals('1000.00', $summary->getTotalInvestment());
        $this->assertDecimalEquals('200.00', $summary->getTotalRecovered());
        $this->assertDecimalEquals('100.00', $summary->getTotalGrossProfit());
        $this->assertDecimalEquals('100.00', $summary->getTotalRecognizedProfit());
        $this->assertDecimalEquals('800.00', $summary->getTotalPending());
        $this->assertDecimalEquals('20.00', $summary->getRecoveryPct());
        $this->assertSame(2, $summary->getUnitsSold());
        $this->assertSame(8, $summary->getUnitsRemaining());
    }

    public function testRegisterSaleWithTwoLinesOfTheSameItemSplitsTheStock(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user, quantity: 10);

        $this->loginAsDemoAdmin($client);
        $this->postSale($client, [
            'investment' => $investment->getId(),
            'customer' => null,
            'date' => '2026-01-15',
            'notes' => null,
            'lines' => [
                ['investmentItem' => $item->getId(), 'quantity' => '6', 'unitPrice' => '150.00'],
                ['investmentItem' => $item->getId(), 'quantity' => '4', 'unitPrice' => '120.00'],
            ],
        ]);

        $sale = $this->onlyPersistedSale();

        // 6 x 150.00 = 900.00  +  4 x 120.00 = 480.00
        $this->assertDecimalEquals('1380.00', $sale->getTotalAmount());
        $this->assertCount(2, $sale->getLines());
        $this->assertSame(0, $this->stockOf($item->getId(), $tenant->getId()), 'las dos líneas descuentan del mismo ítem.');
        $this->assertSaleMovementsAre(-10, $item->getId(), $tenant->getId());

        $summary = $this->summaryOf($investment->getId(), $tenant->getId());
        $this->assertDecimalEquals('1000.00', $summary->getTotalRecovered(), 'todo el costo quedó recuperado.');
        $this->assertDecimalEquals('380.00', $summary->getTotalGrossProfit());
        $this->assertDecimalEquals('0.00', $summary->getTotalPending());
    }

    public function testRegisterSaleCannotExceedStockWithTwoLinesOfTheSameItem(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user, quantity: 10);

        $this->loginAsDemoAdmin($client);
        $this->postSale($client, [
            'investment' => $investment->getId(),
            'customer' => null,
            'date' => '2026-01-15',
            'notes' => null,
            'lines' => [
                ['investmentItem' => $item->getId(), 'quantity' => '6', 'unitPrice' => '150.00'],
                ['investmentItem' => $item->getId(), 'quantity' => '6', 'unitPrice' => '150.00'],
            ],
        ]);

        // El stock se valida en memoria entre líneas del mismo ítem: la
        // segunda línea ve que solo quedan 4, no las 10 originales.
        $this->assertResponseRedirects('/ventas/nueva');
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'Stock insuficiente');

        $this->assertCount(0, $this->em->getRepository(Sale::class)->findAll(), 'la venta no se persiste.');
        $this->assertSame(10, $this->stockOf($item->getId(), $tenant->getId()), 'el stock queda intacto.');
    }

    /**
     * Regresión de Fase 3c.1: SaleLine::setRealUnitCostSnapshot no
     * recalculaba los derivados, así que SaleService leía grossProfitLine
     * '0.00' y TODA venta quedaba con ganancia reconocida en 0.00.
     */
    public function testRegisterSaleWithBargainedPriceRecognizesProfit(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user);

        $this->loginAsDemoAdmin($client);
        $this->postSale($client, [
            'investment' => $investment->getId(),
            'customer' => null,
            'date' => '2026-01-15',
            'notes' => 'Regateo: precio sugerido 140.00',
            'lines' => [[
                'investmentItem' => $item->getId(),
                'quantity' => '3',
                'unitPrice' => '120.00',
            ]],
        ]);

        $sale = $this->onlyPersistedSale();
        $line = $sale->getLines()->first();

        // 3 x 120.00 = 360.00; costo 3 x 100.00 = 300.00.
        $this->assertDecimalEquals('360.00', $sale->getTotalAmount());
        $this->assertDecimalEquals('300.00', $line->getCostRecovered());
        $this->assertDecimalEquals('60.00', $line->getGrossProfitLine());
        $this->assertDecimalEquals('60.00', $line->getRecognizedProfitLine(), 'la ganancia reconocida no puede quedar en 0.00.');

        $summary = $this->summaryOf($investment->getId(), $tenant->getId());
        $this->assertDecimalEquals('60.00', $summary->getTotalRecognizedProfit());
    }

    public function testRegisterSaleWithoutEnoughStockShowsErrorFlash(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user, quantity: 10);
        $this->seedSale($investment, $user, null, null, quantity: 9, unitPrice: '150.00');

        $this->loginAsDemoAdmin($client);
        $this->postSale($client, [
            'investment' => $investment->getId(),
            'customer' => null,
            'date' => '2026-01-15',
            'notes' => null,
            'lines' => [[
                'investmentItem' => $item->getId(),
                'quantity' => '5',
                'unitPrice' => '150.00',
            ]],
        ]);

        $this->assertResponseRedirects('/ventas/nueva');
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'Stock insuficiente');
        $this->assertSelectorTextContains('[role="alert"]', 'disponible 1');

        $this->assertCount(1, $this->em->getRepository(Sale::class)->findAll(), 'sigue existiendo la venta sembrada.');
        $this->assertSame(1, $this->stockOf($item->getId(), $tenant->getId()), 'el stock no cambió.');
    }

    public function testRegisterSaleWithItemOfAnotherInvestmentIsRejected(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $firstItem] = $this->seedConfirmedInvestmentWithStock(
            $user,
            new \DateTimeImmutable('2026-01-05'),
            'Primera'
        );
        ['investment' => $other, 'item' => $otherItem] = $this->seedConfirmedInvestmentWithStock(
            $user,
            new \DateTimeImmutable('2026-02-05'),
            'Segunda'
        );

        $this->loginAsDemoAdmin($client);
        $this->postSale($client, [
            'investment' => $investment->getId(),
            'customer' => null,
            'date' => '2026-01-15',
            'notes' => null,
            'lines' => [[
                'investmentItem' => $otherItem->getId(),
                'quantity' => '2',
                'unitPrice' => '150.00',
            ]],
        ]);

        $this->assertResponseRedirects('/ventas/nueva');
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'pertenece a inversión');

        $this->assertCount(0, $this->em->getRepository(Sale::class)->findAll(), 'la venta no se persiste.');
        $this->assertSame(10, $this->stockOf($otherItem->getId(), $tenant->getId()), 'el stock de la otra inversión queda intacto.');
        $this->assertSame(10, $this->stockOf($firstItem->getId(), $tenant->getId()), 'el stock de la inversión elegida queda intacto.');
    }

    public function testRegisterSaleWithBlankQuantityShowsFieldError(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user);

        $this->loginAsDemoAdmin($client);
        $this->postSale($client, [
            'investment' => $investment->getId(),
            'customer' => null,
            'date' => '2026-01-15',
            'notes' => null,
            'lines' => [[
                'investmentItem' => $item->getId(),
                'quantity' => '0',
                'unitPrice' => '150.00',
            ]],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('[role="alert"]', 'mayor a cero');
        $this->assertCount(0, $this->em->getRepository(Sale::class)->findAll());
    }

    /**
     * @param array{investment: int, customer: ?int, date: string, notes: ?string, lines: list<array{investmentItem: int, quantity: string, unitPrice: string}>} $data
     */
    private function postSale(KernelBrowser $client, array $data): void
    {
        $client->request('POST', '/ventas', [
            'sale_form' => [
                '_token' => $this->saleFormCsrfToken($client),
                'investment' => (string) $data['investment'],
                'customer' => null === $data['customer'] ? '' : (string) $data['customer'],
                'saleDate' => $data['date'],
                'notes' => $data['notes'],
                'lines' => $data['lines'],
            ],
        ]);
    }

    /**
     * Token del form de venta (token id "submit", stateless: el mismo
     * valor sirve para todos los formularios de la sesión).
     */
    private function saleFormCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/ventas/nueva');
        $token = $crawler->filter('input[name="sale_form[_token]"]')->attr('value');

        $this->assertNotNull($token, 'El form de venta debe renderizar su token CSRF.');

        return $token;
    }

    private function onlyPersistedSale(): Sale
    {
        $this->em->clear();
        $sales = $this->em->getRepository(Sale::class)->findAll();
        $this->assertCount(1, $sales, 'Debe existir exactamente una venta registrada.');

        return $sales[0];
    }

    private function stockOf(int $itemId, int $tenantId): int
    {
        return static::getContainer()
            ->get(InventoryMovementRepositoryInterface::class)
            ->getQuantityDeltaSum($itemId, $tenantId);
    }

    /**
     * Los movimientos TYPE_SALE de un ítem deben sumar $expectedDelta:
     * es la prueba de que la cantidad vendida salió del inventario.
     */
    private function assertSaleMovementsAre(int $expectedDelta, int $itemId, int $tenantId): void
    {
        $movements = static::getContainer()
            ->get(InventoryMovementRepositoryInterface::class)
            ->findByItem($itemId, $tenantId);

        $delta = 0;
        foreach ($movements as $movement) {
            if (InventoryMovement::TYPE_SALE === $movement->getMovementType()) {
                $delta += $movement->getQuantityDelta();
            }
        }

        $this->assertSame($expectedDelta, $delta, 'Suma de movimientos de venta del ítem.');
    }

    private function summaryOf(int $investmentId, int $tenantId): InvestmentSummary
    {
        $this->em->clear();

        $summary = static::getContainer()
            ->get(InvestmentSummaryRepositoryInterface::class)
            ->findByInvestment($investmentId, $tenantId);

        $this->assertInstanceOf(InvestmentSummary::class, $summary, 'La inversión debe tener summary.');

        return $summary;
    }
}
