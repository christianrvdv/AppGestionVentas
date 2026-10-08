<?php

namespace App\Tests\Integration\Payment;

use App\DTO\Command\PaymentCommand;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\Payment;
use App\Entity\Sale;
use App\Entity\Tenant;
use App\Exception\Domain\CustomerMismatchException;
use App\Service\Payment\PaymentService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [FIX-7] Estrategia B: el pago no siempre tiene cliente.
 *
 * Antes, con customerId = null, PaymentService llamaba a
 * findByIdAndTenant(null, int) y reventaba con TypeError; además el
 * mapping declaraba payment.customer_id como NOT NULL, así que ni siquiera
 * se podía persistir un pago de mostrador. Ahora:
 *   - customerId null => no se busca cliente y Payment.customer queda null,
 *   - customer_id es nullable en BD (migración Version20261008153127),
 *   - la validación "cliente del pago == cliente de la venta" solo aplica
 *     cuando ambos están definidos.
 */
final class PaymentServiceTest extends IntegrationTestCase
{
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = static::getContainer()->get(PaymentService::class);
    }

    public function test_pago_sin_cliente_se_persiste_con_customer_nulo(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);

        $payment = $this->paymentService->register(
            new PaymentCommand(
                investmentId: $investment->getId(),
                customerId: null,
                saleId: null,
                amount: '50.00',
                date: new \DateTimeImmutable('2026-01-05'),
                method: Payment::METHOD_CASH
            ),
            $user
        );

        $this->assertNull($payment->getCustomer(), 'Un pago de mostrador no tiene cliente.');

        $this->em->persist($payment);
        $this->em->flush();

        $paymentId = $payment->getId();
        $this->em->clear();

        /** @var Payment $reloaded */
        $reloaded = $this->em->getRepository(Payment::class)->find($paymentId);

        $this->assertNull($reloaded->getCustomer());
        $this->assertDecimalEquals('50.00', $reloaded->getAmount());
        $this->assertSame(Payment::METHOD_CASH, $reloaded->getMethod());
    }

    public function test_pago_con_cliente_busca_y_asigna_al_cliente(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $customer = $this->createCustomer($tenant, 'Juan Pérez');

        $payment = $this->paymentService->register(
            new PaymentCommand(
                investmentId: $investment->getId(),
                customerId: $customer->getId(),
                saleId: null,
                amount: '20.00',
                date: new \DateTimeImmutable('2026-01-05'),
                method: Payment::METHOD_TRANSFER
            ),
            $user
        );

        $this->assertNotNull($payment->getCustomer());
        $this->assertSame($customer->getId(), $payment->getCustomer()->getId());

        $this->em->persist($payment);
        $this->em->flush();
        $paymentId = $payment->getId();
        $this->em->clear();

        /** @var Payment $reloaded */
        $reloaded = $this->em->getRepository(Payment::class)->find($paymentId);
        $this->assertSame($customer->getId(), $reloaded->getCustomer()->getId());
    }

    public function test_pago_sin_cliente_sobre_venta_de_mostrador_es_valido(): void
    {
        [$tenant, $user, $investment] = $this->tenantUserInvestment();
        $customer = $this->createCustomer($tenant, 'Cliente de la venta');
        $sale = $this->createSale($tenant, $user, $investment, $customer);

        $payment = $this->paymentService->register(
            new PaymentCommand(
                investmentId: $investment->getId(),
                customerId: null,
                saleId: $sale->getId(),
                amount: '10.00',
                date: new \DateTimeImmutable('2026-01-06'),
                method: Payment::METHOD_CASH
            ),
            $user
        );

        $this->assertNull($payment->getCustomer());
        $this->assertSame($sale->getId(), $payment->getSale()->getId());

        $this->em->persist($payment);
        $this->em->flush();

        $this->assertTrue(true, 'No debe lanzar CustomerMismatchException cuando el pago no define cliente.');
    }

    public function test_rechaza_cliente_distinto_al_de_la_venta(): void
    {
        [$tenant, $user, $investment] = $this->tenantUserInvestment();
        $customerSale = $this->createCustomer($tenant, 'Cliente de la venta');
        $customerPayment = $this->createCustomer($tenant, 'Otro cliente');
        $sale = $this->createSale($tenant, $user, $investment, $customerSale);

        $this->expectException(CustomerMismatchException::class);

        $this->paymentService->register(
            new PaymentCommand(
                investmentId: $investment->getId(),
                customerId: $customerPayment->getId(),
                saleId: $sale->getId(),
                amount: '10.00',
                date: new \DateTimeImmutable('2026-01-06'),
                method: Payment::METHOD_CASH
            ),
            $user
        );
    }

    /**
     * @return array{0: Tenant, 1: \App\Entity\AppUser, 2: Investment}
     */
    private function tenantUserInvestment(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);

        return [$tenant, $user, $investment];
    }

    private function createSale(
        Tenant $tenant,
        \App\Entity\AppUser $user,
        Investment $investment,
        ?Customer $customer
    ): Sale {
        $sale = new Sale();
        $sale->setTenant($tenant);
        $sale->setCreatedBy($user);
        $sale->setInvestment($investment);
        $sale->setCustomer($customer);
        $sale->setCurrency($investment->getBaseCurrency());
        $sale->setSaleDate(new \DateTimeImmutable('2026-01-04'));

        $this->em->persist($sale);
        $this->em->flush();

        return $sale;
    }
}
