<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Tests\Functional\FunctionalTestCase;

final class SaleShowTest extends FunctionalTestCase
{
    public function testShowRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/ventas/1');

        $this->assertResponseRedirects('/login');
    }

    public function testShowRendersSaleDetail(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock(
            $user,
            new \DateTimeImmutable('2026-01-05'),
            'Inversión de enero'
        );
        $customer = $this->seedCustomer($tenant, 'María Pérez');
        $sale = $this->seedSale(
            $investment,
            $user,
            $customer,
            new \DateTimeImmutable('2026-01-15'),
            quantity: 5,
            unitPrice: '150.00'
        );

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas/'.$sale->getId());
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Venta');
        $this->assertSelectorTextContains('h1', (string) $sale->getId());
        $this->assertSelectorTextContains('h1', 'Activa');

        $this->assertStringContainsString('15/01/2026', $body);
        $this->assertStringContainsString('María Pérez', $body);
        $this->assertStringContainsString($investment->getCode(), $body);
        $this->assertStringContainsString('Inversión de enero', $body);

        // Línea: 5 x 150.00 con costo unitario 100.00.
        $this->assertSelectorTextContains('table tbody', 'Producto con stock');
        $this->assertSelectorTextContains('table tbody', '150,00');
        $this->assertSelectorTextContains('table tbody', '750,00');
        $this->assertSelectorTextContains('table tbody', '250,00');

        // Totales de la venta.
        $this->assertSelectorTextContains('#venta-total', '750,00');
        $this->assertSelectorTextContains('#venta-ganancia-bruta', '250,00');
        $this->assertSelectorTextContains('#venta-ganancia-reconocida', '250,00');

        // Vuelta al detalle de la inversión.
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/inversiones/'.$investment->getId().'"]')->count()
        );
    }

    public function testShowRendersPaymentsAndPendingAmount(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, quantity: 5, unitPrice: '150.00');
        $this->seedPayment($sale, '300.00', 'TRANSFER', $this->seedCustomer($tenant, 'María Pérez'), 'REF-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas/'.$sale->getId());
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Transferencia', $body);
        $this->assertStringContainsString('300,00', $body);
        $this->assertSelectorTextContains('#venta-pagado', '300,00');
        $this->assertSelectorTextContains('#venta-pendiente', '450,00');
    }

    public function testShowRendersVoidedBanner(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedVoidedSale($investment, $user, 'Error de tipeo', quantity: 5);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas/'.$sale->getId());
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Anulada');
        $this->assertStringContainsString('Error de tipeo', $body);
        $this->assertStringContainsString('admin@demo.local', $body);
    }

    public function testShowReturns404ForUnknownId(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/ventas/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testShowReturns404ForForeignSale(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $sale = $this->seedForeignSale();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/ventas/'.$sale->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvestmentShowLinksToSaleDetail(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, quantity: 1);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/ventas/'.$sale->getId().'"]')->count()
        );
    }
}
