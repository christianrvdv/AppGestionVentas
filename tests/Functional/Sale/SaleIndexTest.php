<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Tests\Functional\FunctionalTestCase;


final class SaleIndexTest extends FunctionalTestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/ventas');

        $this->assertResponseRedirects('/login');
    }

    public function testIndexPaginatesSales(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedConfirmedInvestmentWithStock($user, quantity: 100)['investment'];

        for ($i = 0; $i < 12; ++$i) {
            $this->seedSale($investment, $user, quantity: 1);
        }

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas');

        $this->assertResponseIsSuccessful();
        $this->assertCount(10, $crawler->filter('table tbody tr'));
        $this->assertGreaterThan(
            0,
            $crawler->filter('nav[aria-label="Paginación de ventas"] a[href*="page=2"]')->count()
        );
    }

    public function testIndexShowsColumnsTotalsAndStatus(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedConfirmedInvestmentWithStock($user, quantity: 20)['investment'];
        $customer = $this->seedCustomer($investment->getTenant(), 'María Pérez');

        $sale = $this->seedSale(
            $investment,
            $user,
            $customer,
            new \DateTimeImmutable('2026-01-15'),
            quantity: 5,
            unitPrice: '150.00'
        );

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas');
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table thead', 'Fecha');
        $this->assertSelectorTextContains('table thead', 'Cliente');
        $this->assertSelectorTextContains('table thead', 'Inversión');
        $this->assertSelectorTextContains('table thead', 'Total');
        $this->assertSelectorTextContains('table thead', 'Ganancia bruta');
        $this->assertSelectorTextContains('table thead', 'Estado');

        $this->assertSelectorTextContains('table tbody', '15/01/2026');
        $this->assertSelectorTextContains('table tbody', 'María Pérez');
        $this->assertSelectorTextContains('table tbody', $investment->getCode());

        // Venta de 5 x 150.00 con costo 100.00: total 750.00 y bruta 250.00.
        $this->assertSelectorTextContains('table tbody', '750,00');
        $this->assertSelectorTextContains('table tbody', '250,00');
        $this->assertSelectorTextContains('table tbody', 'Activa');
        $this->assertSame('750.00', $sale->getTotalAmount());
    }

    public function testIndexExcludesVoidedByDefaultAndIncludesWithFlag(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedConfirmedInvestmentWithStock($user, quantity: 20)['investment'];

        $vivo = $this->seedCustomer($tenant, 'Cliente Vivo');
        $anulado = $this->seedCustomer($tenant, 'Cliente Anulado');
        $this->seedSale($investment, $user, $vivo);
        $this->seedVoidedSale($investment, $user, 'Error de tipeo', $anulado);

        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/ventas');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table tbody', 'Cliente Vivo');
        $this->assertSelectorTextNotContains('table tbody', 'Cliente Anulado');

        $crawler = $client->request('GET', '/ventas?include_voided=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table tbody', 'Cliente Vivo');
        $this->assertSelectorTextContains('table tbody', 'Cliente Anulado');
        $this->assertSelectorTextContains('table tbody', 'Anulada');
    }

    public function testIndexFiltersByInvestment(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $first] = $this->seedConfirmedInvestmentWithStock($user, new \DateTimeImmutable('2026-01-05'), 'Inversión Uno');
        ['investment' => $second] = $this->seedConfirmedInvestmentWithStock($user, new \DateTimeImmutable('2026-02-05'), 'Inversión Dos');

        $this->seedSale($first, $user, quantity: 1);
        $this->seedSale($second, $user, quantity: 1);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas?investment='.$first->getId());

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', $first->getCode());
        $this->assertSelectorTextNotContains('table tbody', $second->getCode());
    }

    public function testIndexFiltersByCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedConfirmedInvestmentWithStock($user, quantity: 20)['investment'];

        $first = $this->seedCustomer($tenant, 'Ana Ruiz');
        $second = $this->seedCustomer($tenant, 'Luis Mora');
        $this->seedSale($investment, $user, $first, quantity: 1);
        $this->seedSale($investment, $user, $second, quantity: 1);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas?customer='.$first->getId());

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', 'Ana Ruiz');
        $this->assertSelectorTextNotContains('table tbody', 'Luis Mora');
    }

    public function testIndexFiltersByDateRange(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedConfirmedInvestmentWithStock($user, quantity: 20)['investment'];

        $this->seedSale($investment, $user, null, new \DateTimeImmutable('2026-01-10'), quantity: 1);
        $this->seedSale($investment, $user, null, new \DateTimeImmutable('2026-02-15'), quantity: 1);
        $this->seedSale($investment, $user, null, new \DateTimeImmutable('2026-03-05'), quantity: 1);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas?from=2026-02-01&to=2026-02-28');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', '15/02/2026');
    }

    public function testIndexIgnoresInvalidDates(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedConfirmedInvestmentWithStock($user, quantity: 20)['investment'];
        $this->seedSale($investment, $user, null, new \DateTimeImmutable('2026-01-10'), quantity: 1);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas?from=no-es-fecha&to=tampoco');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
    }

    public function testIndexOnlyShowsCurrentTenantSales(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedConfirmedInvestmentWithStock($user, quantity: 20)['investment'];
        $this->seedSale($investment, $user, $this->seedCustomer($tenant, 'Cliente del negocio'), quantity: 1);

        $this->seedForeignSale();

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas');
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Cliente del negocio', $body);
        $this->assertStringNotContainsString('Cliente ajeno', $body);
        $this->assertStringNotContainsString('Inversión ajena', $body);
    }

    public function testNavLinksToSalesIndex(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('nav[aria-label="Navegación principal"] a[href="/ventas"]')->count()
        );
    }
}
