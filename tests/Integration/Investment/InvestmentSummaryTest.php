<?php

namespace App\Tests\Integration\Investment;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentSummary;
use App\Entity\Tenant;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Service\Investment\InvestmentItemService;
use App\Service\Investment\InvestmentSummaryService;
use App\Service\Sale\SaleService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [FIX-8] InvestmentSummaryRepository usaba el alias DQL 'is', palabra
 * reservada del parser (IS NULL / IS NOT NULL), así que TODA llamada a
 * InvestmentSummaryService::recompute() lanzaba QueryException y el
 * summary nunca se actualizaba. El alias ahora es 'summary'.
 *
 * Estos tests ejercitan los tres métodos del repositorio contra BD real
 * (findByInvestment, findTopRecovered y sumTotalsByTenant) sin ningún
 * try/catch de tolerancia.
 */
final class InvestmentSummaryTest extends IntegrationTestCase
{
    private InvestmentItemService $itemService;

    private SaleService $saleService;

    private InvestmentSummaryService $summaryService;

    private InvestmentSummaryRepositoryInterface $summaryRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->saleService = $container->get(SaleService::class);
        $this->summaryService = $container->get(InvestmentSummaryService::class);
        $this->summaryRepository = $container->get(InvestmentSummaryRepositoryInterface::class);
    }

    public function test_confirmar_inversion_con_stock_deja_el_summary_con_valores_no_cero(): void
    {
        [$tenant, $user, $investment] = $this->investmentWithStock();

        $summary = $this->summaryRepository->findByInvestment($investment->getId(), $tenant->getId());

        $this->assertNotNull($summary, 'La confirmación debe crear el InvestmentSummary.');
        $this->assertDecimalEquals('50.00', $summary->getTotalInvestment(), 'totalInvestment != 0.');
        $this->assertDecimalEquals('50.00', $summary->getTotalInvestmentCurrent(), 'totalInvestmentCurrent != 0.');
        $this->assertDecimalEquals('50.00', $summary->getTotalPending(), 'totalPending != 0.');
        $this->assertSame(10, $summary->getUnitsRemaining(), 'unitsRemaining != 0.');
        $this->assertSame(0, $summary->getUnitsSold());
        $this->assertNotNull($summary->getConfirmedAt());
    }

    public function test_recompute_sin_ventas_mantiene_todo_el_inventario(): void
    {
        [$tenant, $user, $investment] = $this->investmentWithStock();

        $summary = $this->summaryService->recompute($investment);
        $this->em->flush();

        $this->assertDecimalEquals('50.00', $summary->getTotalInvestment());
        $this->assertDecimalEquals('0.00', $summary->getTotalRecovered(), 'Sin ventas no hay recuperado.');
        $this->assertDecimalEquals('50.00', $summary->getTotalPending());
        $this->assertDecimalEquals('0.00', $summary->getRecoveryPct());
        $this->assertSame(10, $summary->getUnitsRemaining());
        $this->assertSame(0, $summary->getUnitsSold());

        $reloaded = $this->reloadSummary($tenant, $investment);
        $this->assertSame(10, $reloaded->getUnitsRemaining(), 'El summary recalculado quedó persistido.');
    }

    public function test_registrar_venta_recomputa_el_summary_sin_query_exception(): void
    {
        [$tenant, $user, $investment, $item] = $this->investmentWithStock();

        // Sin try/catch: si el alias DQL volviera a romper, el test falla.
        $this->saleService->register(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [new RegisterSaleLineCommand(investmentItemId: $item->getId(), quantity: 2, unitPrice: '10.00')],
            ),
            $user
        );

        $summary = $this->reloadSummary($tenant, $investment);

        $this->assertSame(2, $summary->getUnitsSold(), 'La venta debe reflejarse en unitsSold.');
        $this->assertSame(8, $summary->getUnitsRemaining(), 'Quedan 8 unidades en stock.');
        $this->assertDecimalEquals('10.00', $summary->getTotalRecovered(), '2 uds * coste 5.00.');
        $this->assertDecimalEquals('40.00', $summary->getTotalPending(), '50.00 - 10.00.');
        $this->assertDecimalEquals('20.00', $summary->getRecoveryPct(), '10.00 / 50.00.');
        $this->assertDecimalEquals('10.00', $summary->getTotalGrossProfit(), '2 uds * (10.00 - 5.00).');
    }

    public function test_find_top_recovered_y_sum_totals_usan_el_alias_renombrado(): void
    {
        [$tenant, $user, $investment, $item] = $this->investmentWithStock();

        $this->saleService->register(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [new RegisterSaleLineCommand(investmentItemId: $item->getId(), quantity: 4, unitPrice: '10.00')],
            ),
            $user
        );

        $top = $this->summaryRepository->findTopRecovered($tenant->getId());
        $this->assertCount(1, $top);
        $this->assertSame($investment->getId(), $top[0]->getInvestment()->getId());
        $this->assertDecimalEquals('20.00', $top[0]->getTotalRecovered(), '4 uds * coste 5.00.');

        $totals = $this->summaryRepository->sumTotalsByTenant($tenant->getId());
        $this->assertDecimalEquals('50.00', $totals['totalInvestment']);
        $this->assertDecimalEquals('20.00', $totals['totalRecovered']);
        $this->assertDecimalEquals('30.00', $totals['totalPending']);
    }

    /**
     * @return array{0: Tenant, 1: AppUser, 2: Investment, 3: \App\Entity\InvestmentItem}
     */
    private function investmentWithStock(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $item = $this->itemService->addItem($investment, $product, 10, '5.00');
        $this->em->flush();
        $this->confirmInvestment($investment);

        return [$tenant, $user, $investment, $item];
    }

    private function reloadSummary(Tenant $tenant, Investment $investment): InvestmentSummary
    {
        $summary = $this->summaryRepository->findByInvestment($investment->getId(), $tenant->getId());
        $this->assertNotNull($summary);

        return $summary;
    }
}
