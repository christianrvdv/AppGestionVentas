<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Entity\Payment;
use App\Repository\Contract\PaymentAllocationRepositoryInterface;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Fase 3f / ADR-0018: aplicar un anticipo existente a una venta desde el
 * detalle (POST /ventas/{id}/anticipo) y el nuevo detalle de pago
 * (GET /pagos/{id}).
 *
 * Reglas que ejercitan, por la superficie HTTP:
 *   - el form se pinta solo cuando la venta activa tiene un cliente con
 *     anticipos con remanente;
 *   - el POST es plano con CSRF stateless (token id "submit"), 403 si no
 *     coincide;
 *   - lo que el dominio rechaza (remanente, pendiente, cliente ajeno)
 *     llega como flash de error, nunca como 500;
 *   - una venta de otro negocio es 404 (ADR-0015);
 *   - el detalle del pago separa monto cobrado / aplicado / disponible.
 */
final class PaymentAllocationTest extends FunctionalTestCase
{
    private const APPLY_PATH = '/ventas/%d/anticipo';

    public function testSaleDetailOffersAvailableAdvancesAndAppliesOne(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 2, unitPrice: '150.00');
        $advance = $this->seedAdvance($investment, $customer, '400.00');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas/'.$sale->getId());

        $this->assertSelectorExists('#advance_payment', 'el detalle ofrece el form de anticipo.');
        $this->assertSame(
            (string) $advance->getId(),
            $crawler->filter('#advance_payment option')->last()->attr('value'),
            'el select lista el anticipo del cliente.'
        );

        $client->submit($crawler->filter('form[action$="/anticipo"]')->form(), [
            'payment' => (string) $advance->getId(),
            'amount' => '300.00',
        ]);

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Anticipo de 300,00 aplicado a la venta '.$sale->getId().'.');
        $this->assertSelectorTextContains('#venta-pagado', '300,00');
        $this->assertSelectorTextContains('#venta-pendiente', '0,00');

        // El remanente del anticipo baja: 400 cobrados, 300 aplicados.
        $this->assertSelectorExists(
            '#advance_payment option',
            'sigue habiendo form: quedan 100 sin usar.'
        );

        $this->em->clear();
        $this->assertDecimalEquals(
            '300.00',
            static::getContainer()
                ->get(PaymentAllocationRepositoryInterface::class)
                ->sumByPayment($advance->getId(), $tenant->getId())
        );
    }

    public function testApplyToSaleRejectsInvalidCsrf(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 1);
        $advance = $this->seedAdvance($investment, $customer, '100.00');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'payment' => (string) $advance->getId(),
            'amount' => '10.00',
            '_csrf_token' => 'no-es-un-token',
        ]);

        $this->assertResponseStatusCodeSame(403, 'un POST con CSRF inválido no se ejecuta.');

        $this->em->clear();
        $this->assertSame([], $this->allocationsOf($advance->getId(), $tenant->getId()));
    }

    public function testApplyToSaleShowsErrorWhenPaymentDoesNotExist(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, $this->seedCustomer($tenant, 'Ana'), quantity: 1);

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'payment' => '999999',
            'amount' => '10.00',
            '_csrf_token' => $this->csrfToken(),
        ]);

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'El anticipo no existe.');
        $this->assertSelectorTextContains('#venta-pagado', '0,00');
    }

    public function testApplyToSaleShowsErrorWhenNoAdvanceSelected(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, $this->seedCustomer($tenant, 'Ana'), quantity: 1);

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'amount' => '10.00',
            '_csrf_token' => $this->csrfToken(),
        ]);

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'Seleccioná un anticipo para aplicar.');
    }

    public function testApplyToSaleShowsErrorWhenAmountExceedsPending(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 1, unitPrice: '100.00');
        $advance = $this->seedAdvance($investment, $customer, '500.00');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'payment' => (string) $advance->getId(),
            'amount' => '250.00',
            '_csrf_token' => $this->csrfToken(),
        ]);

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'supera el pendiente de la venta');
        $this->assertSelectorTextContains('#venta-pagado', '0,00');

        $this->em->clear();
        $this->assertSame([], $this->allocationsOf($advance->getId(), $tenant->getId()));
    }

    public function testApplyToSaleShowsErrorWhenAmountExceedsRemaining(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 5, unitPrice: '150.00');
        $advance = $this->seedAdvance($investment, $customer, '50.00');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'payment' => (string) $advance->getId(),
            'amount' => '80.00',
            '_csrf_token' => $this->csrfToken(),
        ]);

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'supera el saldo disponible del pago');

        $this->em->clear();
        $this->assertSame([], $this->allocationsOf($advance->getId(), $tenant->getId()));
    }

    public function testApplyToSaleShowsErrorWhenAdvanceBelongsToAnotherCustomer(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, $this->seedCustomer($tenant, 'Ana'), quantity: 1);
        // El form no lo ofrece (filtra por el cliente de la venta), pero la
        // URL se puede escribir a mano: el dominio es quien lo rechaza.
        $foreign = $this->seedAdvance($investment, $this->seedCustomer($tenant, 'Bruno'), '100.00');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'payment' => (string) $foreign->getId(),
            'amount' => '10.00',
            '_csrf_token' => $this->csrfToken(),
        ]);

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'no coincide');

        $this->em->clear();
        $this->assertSame([], $this->allocationsOf($foreign->getId(), $tenant->getId()));
    }

    public function testApplyToSaleReturns404ForSaleOfAnotherTenant(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $foreign = $this->seedForeignSale();

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $foreign->getId()), [
            'payment' => '1',
            'amount' => '10.00',
            '_csrf_token' => $this->csrfToken(),
        ]);

        $this->assertResponseStatusCodeSame(404, 'una venta ajena es 404, no un flash.');
    }

    public function testApplyToSaleRejectsReapplyingTheSameAdvanceToTheSameSale(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        // Deja pendiente amplio (750 - 300 = 450) y remanente (500 - 300 = 200)
        // para que el único motivo de rechazo sea el vínculo, no los techos.
        $sale = $this->seedSale($investment, $user, $customer, quantity: 5, unitPrice: '150.00');
        $advance = $this->seedAdvance($investment, $customer, '500.00');

        $this->loginAsDemoAdmin($client);

        foreach ([1 => '300,00', 2 => null] as $attempt => $applied) {
            $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
                'payment' => (string) $advance->getId(),
                'amount' => '300.00',
                '_csrf_token' => $this->csrfToken(),
            ]);
            $this->assertResponseRedirects('/ventas/'.$sale->getId());
            $client->followRedirect();

            if (null === $applied) {
                // Doble submit del mismo form: no es un 500 ni un cobro doble.
                $this->assertSelectorTextContains('[role="alert"]', 'ya cubre la venta');
                $this->assertSelectorTextContains('#venta-pagado', '300,00', 'no se aplicó dos veces.');
            } else {
                $this->assertSelectorTextContains('[role="status"]', 'Anticipo de '.$applied.' aplicado');
                $this->assertSelectorTextContains('#venta-pagado', $applied);
            }
        }

        $this->em->clear();
        $this->assertCount(
            1,
            $this->allocationsOf($advance->getId(), $tenant->getId()),
            'una sola allocation pese a dos submits.'
        );
    }

    public function testSaleDetailHidesAdvanceFormWhenNothingIsAvailable(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 1);

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/ventas/'.$sale->getId());

        $this->assertSelectorNotExists('#advance_payment', 'sin anticipos no se ofrece la acción.');
    }

    public function testSaleDetailHidesAdvanceFormForVoidedSale(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        // Anulada antes de cobrar nada, así que sí tendría anticipos
        // disponibles: el motivo para ocultarla es que la venta está cerrada.
        $sale = $this->seedVoidedSale($investment, $user, 'El cliente devolvió todo', $customer, quantity: 1);
        $this->seedAdvance($investment, $customer, '100.00');

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/ventas/'.$sale->getId());

        $this->assertSelectorNotExists('#advance_payment', 'una venta anulada no admite anticipos.');
    }

    public function testPaymentShowSplitsCollectedAppliedAndAvailable(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 3, unitPrice: '100.00');
        $advance = $this->seedAdvance($investment, $customer, '300.00', reference: 'REF-300');

        $this->loginAsDemoAdmin($client);

        // Aún sin aplicar: es un anticipo a cuenta.
        $client->request('GET', '/pagos/'.$advance->getId());
        $this->assertSelectorTextContains('#pago-monto', '300,00');
        $this->assertSelectorTextContains('#pago-aplicado', '0,00');
        $this->assertSelectorTextContains('#pago-disponible', '300,00');
        $this->assertSelectorTextContains('main', 'todavía no cubre ninguna venta', 'anticipo a cuenta, sin ventas.');
        $this->assertSelectorNotExists('#titulos-ventas + div table', 'sin allocations no hay tabla de ventas.');

        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'payment' => (string) $advance->getId(),
            'amount' => '120.00',
            '_csrf_token' => $this->csrfToken(),
        ]);
        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Anticipo de 120,00 aplicado');

        $client->request('GET', '/pagos/'.$advance->getId());
        $this->assertSelectorTextContains('#pago-aplicado', '120,00');
        $this->assertSelectorTextContains('#pago-disponible', '180,00');
        $this->assertPageTitleSame('Pago '.$advance->getId().' · Gestión de Negocio');
        $this->assertSelectorTextContains('td', 'Venta '.$sale->getId(), 'el detalle enlaza la venta cubierta.');
    }

    public function testPaymentShowReturns404ForUnknownPayment(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/pagos/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPaymentShowReturns404ForPaymentOfAnotherTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 1);
        $foreign = $this->seedForeignSale();
        $this->seedPayment($foreign, '999.00');

        $foreignPaymentId = $this->em
            ->getRepository(Payment::class)
            ->findBy(['tenant' => $foreign->getTenant()])[0]
            ->getId();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/pagos/'.$foreignPaymentId);

        $this->assertResponseStatusCodeSame(404, 'un pago ajeno es 404 (ADR-0015).');
    }

    public function testIndexLinksEachPaymentToTheSalesItCovers(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $customer = $this->seedCustomer($tenant, 'Ana');
        $sale = $this->seedSale($investment, $user, $customer, quantity: 1);
        $advance = $this->seedAdvance($investment, $customer, '100.00');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', sprintf(self::APPLY_PATH, $sale->getId()), [
            'payment' => (string) $advance->getId(),
            'amount' => '100.00',
            '_csrf_token' => $this->csrfToken(),
        ]);
        $this->assertResponseRedirects('/ventas/'.$sale->getId());

        $client->request('GET', '/pagos');
        $this->assertSelectorExists(
            'a[href="/ventas/'.$sale->getId().'"]',
            'el listado de pagos muestra las ventas que cubre (M:N resuelto en una consulta).'
        );
    }

    /**
     * Token CSRF plano con id "submit", igual que el que pinta el form
     * (csrf_token('submit')). Stateless: el mismo valor sirve para
     * cualquier POST, así que no hace falta raspar la página.
     */
    private function csrfToken(): string
    {
        return (string) static::getContainer()
            ->get(CsrfTokenManagerInterface::class)
            ->getToken('submit');
    }

    /**
     * @return list<\App\Entity\PaymentAllocation>
     */
    private function allocationsOf(int $paymentId, int $tenantId): array
    {
        return static::getContainer()
            ->get(PaymentAllocationRepositoryInterface::class)
            ->findByPayment($paymentId, $tenantId);
    }
}
