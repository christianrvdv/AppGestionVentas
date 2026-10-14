<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\DTO\Command\PaymentCommand;
use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\Sale;
use App\Entity\Tenant;
use App\Exception\Domain\CurrencyMismatchException;
use App\Exception\Domain\CustomerMismatchException;
use App\Exception\Domain\PaymentAllocationException;
use App\Exception\Domain\SaleHasPaymentsException;
use App\Exception\Domain\TenantMismatchException;
use App\Repository\Contract\PaymentAllocationRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Payment\PaymentService;
use App\Service\Sale\SaleService;
use App\Service\Sale\SaleVoidService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Fase 3f / ADR-0018: PaymentService::applyToSale y el modelo M:N.
 *
 * Las reglas que ejercitan, en el mismo orden en que las valida el
 * servicio: tenant, venta anulada, moneda, cliente, monto positivo,
 * remanente del pago y pendiente de la venta. Y las dos capacidades que
 * el modelo viejo no tenía: repartir un anticipo entre varias ventas y
 * cubrir una venta con varios cobros.
 */
final class PaymentAllocationServiceTest extends IntegrationTestCase
{
    private PaymentService $paymentService;
    private SaleService $saleService;
    private SaleVoidService $voidService;
    private InvestmentItemService $itemService;
    private InvestmentConfirmationService $confirmationService;
    private PaymentRepositoryInterface $paymentRepository;
    private PaymentAllocationRepositoryInterface $allocationRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->paymentService = $container->get(PaymentService::class);
        $this->saleService = $container->get(SaleService::class);
        $this->voidService = $container->get(SaleVoidService::class);
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->confirmationService = $container->get(InvestmentConfirmationService::class);
        $this->paymentRepository = $container->get(PaymentRepositoryInterface::class);
        $this->allocationRepository = $container->get(PaymentAllocationRepositoryInterface::class);
    }

    public function test_aplica_un_anticipo_parcialmente_y_conserva_el_remanente(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 2, '150.00');
        $advance = $this->createAdvance($ctx['investment'], $customer, '500.00');

        $allocation = $this->apply($advance, $sale, '200.00');

        $this->assertDecimalEquals('200.00', $allocation->getAmount());
        $this->assertSame($sale->getId(), $allocation->getSale()->getId());
        $this->assertSame($advance->getId(), $allocation->getPayment()->getId());

        $tenantId = $ctx['tenant']->getId();
        $this->assertDecimalEquals('200.00', $this->paymentRepository->sumBySale($sale->getId(), $tenantId));
        $this->assertDecimalEquals(
            '200.00',
            $this->allocationRepository->sumByPayment($advance->getId(), $tenantId),
            'lo aplicado del anticipo: quedan 300 sin usar.'
        );

        // El dinero que entró no cambió al repartirlo: sigue siendo 500.
        $this->assertDecimalEquals('500.00', $this->paymentRepository->sumByCustomer($customer->getId(), $tenantId));
    }

    public function test_un_anticipo_se_puede_repartir_entre_varias_ventas(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $first = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $second = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $advance = $this->createAdvance($ctx['investment'], $customer, '100.00');

        $this->apply($advance, $first, '60.00');
        $this->apply($advance, $second, '40.00');

        $tenantId = $ctx['tenant']->getId();
        $this->assertDecimalEquals('60.00', $this->paymentRepository->sumBySale($first->getId(), $tenantId));
        $this->assertDecimalEquals('40.00', $this->paymentRepository->sumBySale($second->getId(), $tenantId));

        // Agotado el remanente, un tercer intento no pasa la validación.
        $third = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');

        $this->expectException(PaymentAllocationException::class);
        $this->apply($advance, $third, '1.00');
    }

    public function test_varios_pagos_pueden_cubrir_una_misma_venta(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $first = $this->createAdvance($ctx['investment'], $customer, '40.00');
        $second = $this->createAdvance($ctx['investment'], $customer, '30.00');

        $this->apply($first, $sale, '40.00');
        $this->apply($second, $sale, '30.00');

        $tenantId = $ctx['tenant']->getId();
        $this->assertDecimalEquals('70.00', $this->paymentRepository->sumBySale($sale->getId(), $tenantId));
        $this->assertCount(
            2,
            $this->allocationRepository->findBySale($sale->getId(), $tenantId),
            'una venta puede ser cubierta por varios cobros.'
        );

        // Quedan 30 por cobrar en la venta, pero el segundo cobro ya está
        // agotado: los cubre un tercer cobro. Así ninguna venta depende
        // de que un solo anticipo "alcance".
        $other = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $third = $this->createAdvance($ctx['investment'], $customer, '30.00');
        $this->apply($third, $other, '30.00');
        $this->assertDecimalEquals('30.00', $this->paymentRepository->sumBySale($other->getId(), $tenantId));
    }

    public function test_rechaza_monto_no_positivo(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $advance = $this->createAdvance($ctx['investment'], $customer, '100.00');

        $this->expectException(PaymentAllocationException::class);
        $this->apply($advance, $sale, '0.00');
    }

    public function test_rechaza_monto_mayor_al_remanente_del_pago(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 5, '150.00');
        $advance = $this->createAdvance($ctx['investment'], $customer, '50.00');

        try {
            $this->apply($advance, $sale, '80.00');
            $this->fail('Debía rechazarse: 80 supera el remanente de 50.');
        } catch (PaymentAllocationException $e) {
            $this->assertStringContainsString('saldo disponible del pago', $e->getMessage());
        }

        $this->assertSame(
            [],
            $this->allocationRepository->findByPayment($advance->getId(), $ctx['tenant']->getId()),
            'un rechazo no deja allocation a medias.'
        );
    }

    public function test_rechaza_monto_mayor_al_pendiente_de_la_venta(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $advance = $this->createAdvance($ctx['investment'], $customer, '500.00');

        // Ya hay 70 cobrados: el pendiente real es 30.
        $this->apply($this->createAdvance($ctx['investment'], $customer, '70.00'), $sale, '70.00');

        try {
            $this->apply($advance, $sale, '50.00');
            $this->fail('Debía rechazarse: 50 supera el pendiente de 30.');
        } catch (PaymentAllocationException $e) {
            $this->assertStringContainsString('pendiente de la venta', $e->getMessage());
        }
    }

    public function test_rechaza_venta_anulada(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $advance = $this->createAdvance($ctx['investment'], $customer, '100.00');

        $this->voidService->void($sale, 'Devolución del cliente', $ctx['user']);
        $this->em->flush();

        $this->expectException(PaymentAllocationException::class);
        $this->apply($advance, $sale, '10.00');
    }

    public function test_rechaza_cliente_distinto_al_de_la_venta(): void
    {
        $ctx = $this->seed();
        $saleCustomer = $this->createCustomer($ctx['tenant'], 'Ana');
        $otherCustomer = $this->createCustomer($ctx['tenant'], 'Bruno');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $saleCustomer, 1, '100.00');
        $advance = $this->createAdvance($ctx['investment'], $otherCustomer, '100.00');

        $this->expectException(CustomerMismatchException::class);
        $this->apply($advance, $sale, '10.00');
    }

    public function test_rechaza_moneda_distinta(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');

        $euroInvestment = $this->createInvestment($ctx['tenant'], $ctx['user'], 'INV-EUR');
        $euroInvestment->setBaseCurrency('EUR');
        $product = $this->createProduct($ctx['tenant'], 'Producto en euros');
        $this->itemService->addItem($euroInvestment, $product, 10, '100.00');
        $this->em->flush();
        $this->confirmationService->confirm($euroInvestment);
        $this->em->flush();

        $advance = $this->createAdvance($euroInvestment, $customer, '100.00');

        $this->expectException(CurrencyMismatchException::class);
        $this->apply($advance, $sale, '10.00');
    }

    public function test_rechaza_pago_y_venta_de_otro_tenant(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');

        $otherTenant = $this->createTenant('otro-negocio');
        $otherUser = $this->createAppUser($otherTenant, 'otro@example.com');
        $otherInvestment = $this->createInvestment($otherTenant, $otherUser, 'INV-OTRO');
        $otherProduct = $this->createProduct($otherTenant, 'Producto ajeno');
        $this->itemService->addItem($otherInvestment, $otherProduct, 10, '100.00');
        $this->em->flush();
        $this->confirmationService->confirm($otherInvestment);
        $this->em->flush();
        $foreignAdvance = $this->createAdvance($otherInvestment, null, '100.00');

        // Vuelvo al tenant del test: el pago ajeno debe rechazarse aunque
        // la venta sea "de este negocio".
        $this->tenantContext->setTenantId($ctx['tenant']->getId());

        $this->expectException(TenantMismatchException::class);
        $this->apply($foreignAdvance, $sale, '10.00');
    }

    public function test_rechaza_reaplicar_el_mismo_anticipo_a_la_misma_venta(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 5, '150.00');
        $advance = $this->createAdvance($ctx['investment'], $customer, '500.00');

        $this->apply($advance, $sale, '300.00');

        // Un segundo tramo contra la MISMA venta: los techos de remanente
        // (200) y de pendiente (450) lo dejan pasar, así que solo la
        // unique (payment_id, sale_id) lo frena. Tiene que salir como
        // error de dominio, no como violación de índice (500).
        try {
            $this->apply($advance, $sale, '150.00');
            $this->fail('Debía rechazarse: el anticipo ya cubre esta venta.');
        } catch (PaymentAllocationException $e) {
            $this->assertStringContainsString('ya cubre', $e->getMessage());
        }
    }

    public function test_no_se_puede_anular_una_venta_con_importes_aplicados(): void
    {
        $ctx = $this->seed();
        $customer = $this->createCustomer($ctx['tenant'], 'Ana');
        $sale = $this->createSale($ctx['investment'], $ctx['user'], $customer, 1, '100.00');
        $this->apply($this->createAdvance($ctx['investment'], $customer, '100.00'), $sale, '100.00');

        $this->expectException(SaleHasPaymentsException::class);

        $this->voidService->void($sale, 'Devolución', $ctx['user']);
    }

    /**
     * Aplica el anticipo y flushea, igual que hace el controller: el
     * servicio no flushea (ADR-0017), así que sin este flush la siguiente
     * lectura SQL no vería la allocation y las validaciones de techo no
     * se activarían.
     */
    private function apply(Payment $payment, Sale $sale, string $amount): PaymentAllocation
    {
        $allocation = $this->paymentService->applyToSale($payment, $sale, $amount);
        $this->em->persist($allocation);
        $this->em->flush();

        return $allocation;
    }

    /**
     * @return array{tenant: Tenant, user: AppUser, investment: Investment}
     */
    private function seed(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant, 'Producto allocation');
        $this->itemService->addItem($investment, $product, 10, '100.00');
        $this->em->flush();
        $this->confirmationService->confirm($investment);
        $this->em->flush();

        return ['tenant' => $tenant, 'user' => $user, 'investment' => $investment];
    }

    private function createSale(
        Investment $investment,
        AppUser $user,
        ?Customer $customer,
        int $quantity,
        string $unitPrice
    ): Sale {
        $item = $this->firstItem($investment);

        $sale = $this->saleService->register(
            new RegisterSaleCommand(
                $investment->getId(),
                $customer?->getId(),
                new \DateTimeImmutable('2026-02-01'),
                [new RegisterSaleLineCommand($item->getId(), $quantity, $unitPrice)]
            ),
            $user
        );
        $this->em->flush();

        return $sale;
    }

    private function createAdvance(Investment $investment, ?Customer $customer, string $amount): Payment
    {
        $payment = $this->paymentService->register(
            new PaymentCommand(
                investmentId: $investment->getId(),
                customerId: $customer?->getId(),
                saleId: null,
                amount: $amount,
                date: new \DateTimeImmutable('2026-02-02'),
                method: Payment::METHOD_CASH
            ),
            $this->userOf($investment)
        );
        $this->em->persist($payment);
        $this->em->flush();

        return $payment;
    }

    private function firstItem(Investment $investment): InvestmentItem
    {
        $item = $investment->getItems()->first();

        if (false === $item) {
            throw new \LogicException(sprintf('La inversión %d no tiene ítems para vender.', $investment->getId()));
        }

        return $item;
    }

    private function userOf(Investment $investment): AppUser
    {
        return $investment->getCreatedBy();
    }
}
