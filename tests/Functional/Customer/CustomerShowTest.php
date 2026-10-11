<?php

declare(strict_types=1);

namespace App\Tests\Functional\Customer;

use App\Entity\Payment;
use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Fase 3d: detalle del cliente (/clientes/{id}).
 *
 * Reglas que ejercitan:
 *   - Saldo = (total vendido sin anuladas) − (total pagado).
 *   - Los anticipos son los pagos sin venta asociada.
 *   - Una venta anulada no infla el saldo.
 *   - Los pagos del cliente se paginan; las ventas, no (son pocas).
 */
final class CustomerShowTest extends FunctionalTestCase
{
    public function testShowRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $client->request('GET', '/clientes/'.$customer->getId());

        $this->assertResponseRedirects('/login');
    }

    public function testShowRendersBalanceAdvancesAndHistory(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez', '555-0001', 'maria@example.com');

        // Venta viva de 750, cobrada 300 => saldo 450.
        $sale = $this->seedSale($investment, $user, $customer, quantity: 5, unitPrice: '150.00');
        $this->seedPayment($sale, '300.00', Payment::METHOD_TRANSFER, $customer, 'REF-1');

        // Venta anulada de 300: NO debe inflar el total vendido.
        $this->seedVoidedSale($investment, $user, 'Error de tipeo', $customer, null, 2, '150.00');

        // Anticipo de 100 (sin venta): total pagado 400, saldo 350.
        $this->seedAdvance($investment, $customer, '100.00', Payment::METHOD_CASH, null, 'ANT-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes/'.$customer->getId());
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'María Pérez');
        $this->assertSelectorTextContains('h1', 'Activo');

        $this->assertSelectorTextContains('#cliente-total-vendido', '750,00', 'la venta anulada no cuenta.');
        $this->assertSelectorTextContains('#cliente-total-pagado', '400,00');
        $this->assertSelectorTextContains('#cliente-saldo', '350,00');
        $this->assertSelectorTextContains('#cliente-anticipos', '100,00');

        // Datos del cliente.
        $this->assertStringContainsString('555-0001', $body);
        $this->assertStringContainsString('maria@example.com', $body);

        // Historial: la venta viva sí, la anulada no.
        $this->assertStringContainsString('Venta '.$sale->getId(), $body);
        $this->assertStringContainsString('Transferencia', $body);
        $this->assertStringContainsString('Efectivo', $body);
        $this->assertStringContainsString('ANT-1', $body);
        $this->assertStringContainsString('A cuenta', $body);
    }

    public function testShowRendersEmptyStateForCustomerWithoutHistory(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes/'.$customer->getId());
        $body = $crawler->filter('body')->text();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#cliente-saldo', '0,00');
        $this->assertStringContainsString('Este cliente no tiene ventas registradas.', $body);
        $this->assertStringContainsString('Este cliente no tiene pagos registrados.', $body);
    }

    public function testShowPaginatesPayments(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');

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
        $crawler = $client->request('GET', '/clientes/'.$customer->getId());

        $this->assertResponseIsSuccessful();
        $this->assertCount(10, $crawler->filter('section[aria-labelledby="titulos-pagos"] table tbody tr'));
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/clientes/'.$customer->getId().'?page=2"]')->count()
        );
    }

    public function testShowReturns404ForUnknownId(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/clientes/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testShowReturns404ForForeignCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $foreignTenant = new \App\Entity\Tenant();
        $foreignTenant->setName('Ajeno');
        $foreignTenant->setSlug('ajeno');
        $this->em->persist($foreignTenant);
        $this->em->flush();

        $foreignCustomer = $this->seedCustomer($foreignTenant, 'Cliente ajeno');
        $foreignId = $foreignCustomer->getId();

        $this->em->clear();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/clientes/'.$foreignId);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCustomerIndexLinksToDetail(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/clientes/'.$customer->getId().'"]')->count(),
            'el listado debe enlazar al detalle del cliente.'
        );
    }
}
