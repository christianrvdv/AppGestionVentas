<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Entity\Payment;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Fase 3d: listado de pagos (/pagos).
 *
 * Reglas que ejercitan:
 *   - 10 pagos por página, con orden estable (fecha DESC, id DESC).
 *   - Filtros por cliente, venta, método y rango de fechas; un parámetro
 *     inválido se ignora en silencio (no 500).
 *   - Los pagos de otro negocio no aparecen.
 */
final class PaymentIndexTest extends FunctionalTestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/pagos');

        $this->assertResponseRedirects('/login');
    }

    public function testIndexListsPaymentsWithCustomerSaleAndMethod(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 2, unitPrice: '150.00');
        $this->seedPayment($sale, '300.00', Payment::METHOD_TRANSFER, $customer, 'REF-1');
        $this->seedAdvance($investment, $customer, '100.00', Payment::METHOD_CASH, null, 'ANT-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/pagos');
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Pagos');
        $this->assertStringContainsString('María Pérez', $body);
        $this->assertStringContainsString('Transferencia', $body);
        $this->assertStringContainsString('Efectivo', $body);
        $this->assertStringContainsString('REF-1', $body);
        $this->assertStringContainsString('ANT-1', $body);
        $this->assertStringContainsString('A cuenta', $body, 'un anticipo no tiene venta asociada.');

        // La venta sí se enlaza; el anticipo no.
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/ventas/'.$sale->getId().'"]')->count()
        );
    }

    public function testIndexFiltersByCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $maria = $this->seedCustomer($tenant, 'María Pérez');
        $juan = $this->seedCustomer($tenant, 'Juan Gómez');

        $this->seedAdvance($investment, $maria, '100.00');
        $this->seedAdvance($investment, $juan, '250.00');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/pagos?customer='.$maria->getId());
        // El desplegable de filtros lista a todos los clientes del negocio,
        // así que las afirmaciones se limitan a las filas del listado.
        $body = $crawler->filter('table tbody')->text();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('María Pérez', $body);
        $this->assertStringNotContainsString('Juan Gómez', $body);
        $this->assertStringContainsString('100,00', $body);
        $this->assertStringNotContainsString('250,00', $body);
    }

    public function testIndexFiltersBySale(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 1, unitPrice: '150.00');
        $other = $this->seedSale($investment, $user, $customer, quantity: 1, unitPrice: '150.00');

        $this->seedPayment($sale, '150.00', Payment::METHOD_CASH, $customer);
        $this->seedPayment($other, '90.00', Payment::METHOD_CASH, $customer);

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/pagos?sale='.$sale->getId());
        $body = $client->getCrawler()->filter('table tbody')->text();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('150,00', $body);
        $this->assertStringNotContainsString('90,00', $body);
    }

    public function testIndexFiltersByMethod(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $this->seedAdvance($investment, $customer, '100.00', Payment::METHOD_CASH);
        $this->seedAdvance($investment, $customer, '250.00', Payment::METHOD_TRANSFER);

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/pagos?method=TRANSFER');
        $body = $client->getCrawler()->filter('table tbody')->text();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('250,00', $body);
        $this->assertStringNotContainsString('100,00', $body);
    }

    public function testIndexFiltersByDateRange(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $this->seedAdvance($investment, $customer, '100.00', Payment::METHOD_CASH, new \DateTimeImmutable('2026-01-05'));
        $this->seedAdvance($investment, $customer, '250.00', Payment::METHOD_CASH, new \DateTimeImmutable('2026-03-20'));

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/pagos?from=2026-03-01&to=2026-03-31');
        $body = $client->getCrawler()->filter('table tbody')->text();

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('250,00', $body);
        $this->assertStringNotContainsString('100,00', $body);
    }

    public function testIndexIgnoresInvalidFilters(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');
        $this->seedAdvance($investment, $customer, '100.00');

        $this->loginAsDemoAdmin($client);
        // Query string editada a mano: ids no numéricos, fechas rotas y un
        // método que no existe no deben tumbar el listado.
        $client->request('GET', '/pagos?customer=abc&sale=xyz&from=99-99-99&to=&method=NOPE');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('100,00', $client->getCrawler()->text());
    }

    public function testIndexPaginatesAtTenRowsPerPage(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        // 11 anticipos => 10 en la primera página y 1 en la segunda.
        for ($i = 1; $i <= 11; ++$i) {
            $this->seedAdvance(
                $investment,
                $customer,
                '10.00',
                Payment::METHOD_CASH,
                new \DateTimeImmutable('2026-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)),
                'ANT-'.$i
            );
        }

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/pagos');

        $this->assertResponseIsSuccessful();
        $this->assertCount(10, $crawler->filter('table tbody tr'), 'la primera página trae 10 pagos.');
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/pagos?page=2"]')->count(),
            'debe haber enlace a la segunda página.'
        );

        $client->request('GET', '/pagos?page=2');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $client->getCrawler()->filter('table tbody tr'), 'la segunda página trae el pago restante.');
    }

    public function testIndexHidesPaymentsOfOtherTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $foreignSale = $this->seedForeignSale();
        $this->seedPayment($foreignSale, '777.00', Payment::METHOD_CASH, null, 'AJENO-1');

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/pagos');
        $body = $client->getCrawler()->text();

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('777,00', $body);
        $this->assertStringNotContainsString('AJENO-1', $body);
    }

    public function testIndexShowsEmptyState(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/pagos');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('p', 'No hay pagos registrados.');
    }

    /**
     * El nav principal debe marcar "Pagos" como la sección activa.
     */
    public function testIndexMarksPaymentsNavAsActive(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/pagos');

        $nav = $crawler->filter('nav[aria-label="Navegación principal"] a[href="/pagos"]');

        $this->assertCount(1, $nav);
        $this->assertSame('page', $nav->attr('aria-current'), 'Pagos debe ser la sección activa (aria-current="page").');
    }
}
