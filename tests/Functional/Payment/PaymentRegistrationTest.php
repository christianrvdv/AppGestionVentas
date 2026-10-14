<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fase 3d: registro de pagos desde la UI.
 *
 * Reglas que ejercitan:
 *   - /ventas/{id}/pago no pide inversión ni cliente: los toma la venta.
 *   - /pagos/nuevo es un anticipo y sí pide inversión y cliente.
 *   - El CSRF se valida en el controller (token id "submit", stateless):
 *     un token inválido responde 403, igual que en los POST planos.
 *   - Una venta anulada no admite cobros.
 *   - El monto debe ser mayor que cero (validación de campo, no de
 *     dominio: se re-renderiza con el error en su sitio).
 */
final class PaymentRegistrationTest extends FunctionalTestCase
{
    public function testNewForSaleRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, quantity: 1);

        $client->request('GET', '/ventas/'.$sale->getId().'/pago');

        $this->assertResponseRedirects('/login');
    }

    public function testNewRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/pagos/nuevo');

        $this->assertResponseRedirects('/login');
    }

    public function testNewForSaleShowsPendingAndOmitsInvestmentAndCustomerFields(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 5, unitPrice: '150.00');
        $this->seedPayment($sale, '300.00', Payment::METHOD_CASH, $customer);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas/'.$sale->getId().'/pago');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Venta '.$sale->getId());
        $this->assertSelectorTextContains('#pago-total-pagado', '300,00');
        $this->assertSelectorTextContains('#pago-pendiente', '450,00');
        $this->assertStringContainsString('María Pérez', $crawler->filter('body')->text());

        // Inversión y cliente NO son campos editables: los toma la venta.
        $this->assertSelectorNotExists('form[name="payment"] select[name="payment[investment]"]');
        $this->assertSelectorNotExists('form[name="payment"] select[name="payment[customer]"]');

        $this->assertSelectorExists('input[name="payment[amount]"]');
        $this->assertSelectorExists('input[name="payment[date]"]');
        $this->assertSelectorExists('select[name="payment[method]"]');
        $this->assertSelectorExists('input[name="payment[reference]"]');
    }

    public function testRegisterPaymentOnSalePersistsAndRedirectsToSaleDetail(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 5, unitPrice: '150.00');
        $saleId = $sale->getId();

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/ventas/'.$saleId.'/pago', [
            'payment' => [
                '_token' => $this->formToken($client, '/ventas/'.$saleId.'/pago'),
                'amount' => '300.00',
                'date' => '2026-02-10',
                'method' => Payment::METHOD_TRANSFER,
                'reference' => 'REF-9',
                'notes' => 'Seña del saldo',
            ],
        ]);

        $this->assertResponseRedirects('/ventas/'.$saleId);
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Pago de 300,00 registrado.');
        $this->assertSelectorTextContains('#venta-pagado', '300,00');
        $this->assertSelectorTextContains('#venta-pendiente', '450,00');

        $this->em->clear();
        $payments = static::getContainer()
            ->get(PaymentRepositoryInterface::class)
            ->findBySale($saleId, $tenant->getId());

        $this->assertCount(1, $payments);
        $payment = $payments[0];
        $this->assertDecimalEquals('300.00', $payment->getAmount());
        $this->assertSame(Payment::METHOD_TRANSFER, $payment->getMethod());
        $this->assertSame('REF-9', $payment->getReference());
        $this->assertSame('Seña del saldo', $payment->getNotes());
        $allocation = $payment->getAllocations()->first();
        $this->assertNotNull($allocation, 'el pago queda aplicado a la venta.');
        $this->assertSame($saleId, $allocation->getSale()->getId());
        $this->assertNotNull($payment->getCustomer(), 'el cliente lo toma la venta.');
        $this->assertSame(
            $investment->getBaseCurrency(),
            $payment->getCurrency(),
            'la moneda la define la inversión de la venta.'
        );
        $this->assertSame('2026-02-10', $payment->getPaymentDate()->format('Y-m-d'));
    }

    public function testRegisterPaymentOnSaleWithoutCustomerKeepsNullCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, null, quantity: 1, unitPrice: '100.00');
        $saleId = $sale->getId();
        $tenantId = $this->tenantIdOf($user);

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/ventas/'.$saleId.'/pago', [
            'payment' => [
                '_token' => $this->formToken($client, '/ventas/'.$saleId.'/pago'),
                'amount' => '100.00',
                'date' => '2026-02-10',
                'method' => Payment::METHOD_CASH,
                'reference' => null,
                'notes' => null,
            ],
        ]);

        $this->assertResponseRedirects('/ventas/'.$saleId);

        $this->em->clear();
        $payments = static::getContainer()
            ->get(PaymentRepositoryInterface::class)
            ->findBySale($saleId, $tenantId);

        $this->assertCount(1, $payments);
        $this->assertNull($payments[0]->getCustomer(), 'pago de mostrador: sin cliente.');
    }

    /**
     * El controller valida el token ANTES de handleRequest: si se lo dejara
     * solo al form de Symfony, un token caducado re-renderizaría la página
     * sin ningún mensaje (el campo _token no se pinta). 403, igual que los
     * controllers de POST plano.
     */
    public function testRegisterPaymentRejectsInvalidCsrfToken(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, quantity: 1);
        $saleId = $sale->getId();

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/ventas/'.$saleId.'/pago', [
            'payment' => [
                '_token' => 'no-es-un-token',
                'amount' => '100.00',
                'date' => '2026-02-10',
                'method' => Payment::METHOD_CASH,
            ],
        ]);

        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->assertSame(
            0,
            $this->countAllocationsForSale($saleId),
            'con CSRF inválido no se persiste nada.'
        );
    }

    public function testRegisterPaymentRequiresPositiveAmount(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, quantity: 1);
        $saleId = $sale->getId();

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/ventas/'.$saleId.'/pago', [
            'payment' => [
                '_token' => $this->formToken($client, '/ventas/'.$saleId.'/pago'),
                'amount' => '0',
                'date' => '2026-02-10',
                'method' => Payment::METHOD_CASH,
            ],
        ]);

        // Form inválido: se re-renderiza con el error de campo en su sitio.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El monto del pago debe ser mayor que cero.');

        $this->em->clear();
        $this->assertSame(0, $this->countAllocationsForSale($saleId));
    }

    public function testRegisterPaymentOnVoidedSaleIsRejected(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedVoidedSale($investment, $user, 'Error de tipeo', quantity: 2);
        $saleId = $sale->getId();

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/ventas/'.$saleId.'/pago', [
            'payment' => [
                '_token' => $this->formToken($client, '/ventas/'.$saleId.'/pago'),
                'amount' => '100.00',
                'date' => '2026-02-10',
                'method' => Payment::METHOD_CASH,
            ],
        ]);

        $this->assertResponseRedirects('/ventas/'.$saleId);
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'No se pueden registrar pagos sobre una venta anulada.');

        $this->em->clear();
        $this->assertSame(0, $this->countAllocationsForSale($saleId));
    }

    public function testNewForSaleReturns404ForUnknownSale(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/ventas/999999/pago');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNewForSaleReturns404ForForeignSale(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $sale = $this->seedForeignSale();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/ventas/'.$sale->getId().'/pago');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNewAdvanceShowsInvestmentAndCustomerSelectors(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $this->seedCustomer($tenant, 'María Pérez');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/pagos/nuevo');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Registrar pago a cuenta');
        $this->assertSelectorExists('select[name="payment[investment]"]');
        $this->assertSelectorExists('select[name="payment[customer]"]');
        $this->assertGreaterThan(
            0,
            $crawler->filter('select[name="payment[investment]"] option[value="'.$investment->getId().'"]')->count()
        );
    }

    public function testRegisterAdvancePersistsWithoutSaleAndRedirectsToPaymentIndex(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/pagos', [
            'payment' => [
                '_token' => $this->formToken($client, '/pagos/nuevo'),
                'investment' => (string) $investment->getId(),
                'customer' => (string) $customer->getId(),
                'amount' => '200.00',
                'date' => '2026-02-11',
                'method' => Payment::METHOD_CASH,
                'reference' => 'ANT-1',
                'notes' => 'Anticipo para el próximo pedido',
            ],
        ]);

        $this->assertResponseRedirects('/pagos');
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Pago de 200,00 registrado.');
        $this->assertStringContainsString('ANT-1', $client->getCrawler()->text());

        $this->em->clear();
        $payments = static::getContainer()
            ->get(PaymentRepositoryInterface::class)
            ->findByCustomer($customer->getId(), $tenant->getId());

        $this->assertCount(1, $payments);
        $payment = $payments[0];
        $this->assertCount(0, $payment->getAllocations(), 'un anticipo no está aplicado a ninguna venta.');
        $this->assertNotNull($payment->getCustomer());
        $this->assertDecimalEquals('200.00', $payment->getAmount());
        $this->assertSame($investment->getBaseCurrency(), $payment->getCurrency());
    }

    public function testRegisterAdvanceRequiresCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $tenantId = $this->tenantIdOf($user);

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/pagos', [
            'payment' => [
                '_token' => $this->formToken($client, '/pagos/nuevo'),
                'investment' => (string) $investment->getId(),
                'customer' => '',
                'amount' => '200.00',
                'date' => '2026-02-11',
                'method' => Payment::METHOD_CASH,
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[role="alert"]',
            'Un pago a cuenta necesita un cliente a quien acreditarlo.'
        );

        $this->em->clear();
        $this->assertSame(
            0,
            $this->countPaymentsByTenant($tenantId),
            'sin cliente no se persiste el anticipo.'
        );
    }

    public function testRegisterAdvanceRejectsUnknownInvestment(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        $customer = $this->seedCustomer($tenant, 'María Pérez');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/pagos', [
            'payment' => [
                '_token' => $this->formToken($client, '/pagos/nuevo'),
                'investment' => '999999',
                'customer' => (string) $customer->getId(),
                'amount' => '200.00',
                'date' => '2026-02-11',
                'method' => Payment::METHOD_CASH,
            ],
        ]);

        // El form limita las inversiones a las del tenant: una desconocida
        // no llega al servicio, se invalida en el campo.
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $this->assertSame(0, $this->countPaymentsByTenant($tenant->getId()));
    }

    /**
     * Token CSRF plano del form de Symfony (token id "submit", stateless:
     * el mismo valor sirve para cualquier POST).
     */
    private function formToken(KernelBrowser $client, string $url): string
    {
        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('form input[name="payment[_token]"]')->attr('value');

        $this->assertNotNull($token, 'El form debe renderizar su token CSRF.');

        return $token;
    }

    /**
     * Importes aplicados a una venta. Desde ADR-0018 no hay un campo
     * Payment.sale que contar: el vínculo es PaymentAllocation.
     */
    private function countAllocationsForSale(int $saleId): int
    {
        $result = $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(PaymentAllocation::class, 'a')
            ->andWhere('a.sale = :saleId')
            ->setParameter('saleId', $saleId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    private function countPaymentsByTenant(int $tenantId): int
    {
        $result = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
