<?php

declare(strict_types=1);

namespace App\Tests\Integration\Sale;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentSummary;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Service\Investment\InvestmentItemService;
use App\Service\Sale\SaleService;
use App\Service\Sale\SaleVoidService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [ADR-0017] Política de flush en servicios que recalculan el summary.
 *
 * InvestmentSummaryService::recompute() escribe en la entidad InvestmentSummary,
 * que ya está en la Unit of Work. Si nadie hace flush después, esos valores
 * viven solo en memoria: la siguiente petición (otro EM) lee una fila vieja.
 *
 * El llamador no puede ser responsable de eso porque no siempre sabe que hubo
 * una recompute — y SaleVoidService hace DOS (una en void() y otra en
 * updateInvestmentState()), intercaladas con cambios de estado.
 *
 * Estos tests anulan una venta y registran una merma SIN flush del lado del
 * test y limpian el EntityManager antes de leer: solo pasa lo que quedó
 * realmente en la base de datos.
 */
final class SaleVoidFlushTest extends IntegrationTestCase
{
    private SaleService $saleService;

    private SaleVoidService $saleVoidService;

    private InvestmentItemService $itemService;

    private InvestmentSummaryRepositoryInterface $summaryRepository;

    private SaleRepositoryInterface $saleRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->saleService = $container->get(SaleService::class);
        $this->saleVoidService = $container->get(SaleVoidService::class);
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->summaryRepository = $container->get(InvestmentSummaryRepositoryInterface::class);
        $this->saleRepository = $container->get(SaleRepositoryInterface::class);
    }

    /**
     * 10 unidades a 5.00 (inversión de 50.00), se venden 2 y se anula.
     * El summary debe quedar en cero recupero con TODO el stock de vuelta.
     */
    public function test_anular_venta_sin_flush_externo_deja_el_summary_correcto_en_bd(): void
    {
        $seed = $this->seedConfirmedInvestmentWithSale();

        $before = $this->summaryFromDatabase($seed['investmentId']);
        $this->assertSame(2, $before->getUnitsSold(), 'Precondición: la venta movió el summary.');
        $this->assertDecimalEquals('10.00', $before->getTotalRecovered(), 'Precondición: 2 uds * coste 5.00.');

        $this->voidSale($seed);

        $after = $this->summaryFromDatabase($seed['investmentId']);

        $this->assertSame(0, $after->getUnitsSold(), 'La venta anulada no cuenta como venta.');
        $this->assertSame(10, $after->getUnitsRemaining(), 'Las 2 unidades vuelven al stock.');
        $this->assertSame(0, $after->getUnitsLost());
        $this->assertDecimalEquals('0.00', $after->getTotalRecovered(), 'Nada recuperado tras anular.');
        $this->assertDecimalEquals('50.00', $after->getTotalPending(), 'Vuelve a deber la inversión completa.');
        $this->assertDecimalEquals('50.00', $after->getTotalInvestment());
        $this->assertDecimalEquals('0.00', $after->getRecoveryPct());
        $this->assertDecimalEquals('0.00', $after->getTotalGrossProfit(), 'Sin beneficio: la venta no existió.');
        $this->assertDecimalEquals('0.00', $after->getTotalRecognizedProfit());
    }

    /**
     * La anulación también retrocede el estado de la inversión de PARTIAL a
     * OPEN. Es el mismo problema que el summary: un setter en una entidad ya
     * persistida no llega a la base sin flush.
     */
    public function test_anular_venta_sin_flush_externo_deja_el_estado_de_inversion_en_bd(): void
    {
        $seed = $this->seedConfirmedInvestmentWithSale();

        $this->voidSale($seed);

        $this->em->clear();
        $investment = $this->em->getRepository(Investment::class)->find($seed['investmentId']);
        $this->assertNotNull($investment);
        $this->assertSame(
            Investment::STATUS_OPEN,
            $investment->getStatus(),
            'Sin ventas activas la inversión vuelve a OPEN (STATUS_PARTIAL es viejo).'
        );
    }

    /**
     * Anula SIN flush del lado del test: lo único que puede guardar el
     * summary es el propio servicio.
     *
     * @param array{investmentId: int, saleId: int, userId: int, tenantId: int} $seed
     */
    private function voidSale(array $seed): void
    {
        $sale = $this->saleRepository->findByIdAndTenant($seed['saleId'], $seed['tenantId']);
        $this->assertNotNull($sale, 'Precondición: la venta existe.');

        $user = $this->em->getRepository(AppUser::class)->find($seed['userId']);
        $this->assertNotNull($user, 'Precondición: el usuario existe.');

        $this->saleVoidService->void($sale, 'Error de captura', $user);
    }

    /**
     * Lee el summary DESPUÉS de descartar la Unit of Work: cualquier valor
     * que solo existiera en memoria desaparece con clear() y la aserción
     * falla.
     */
    private function summaryFromDatabase(int $investmentId): InvestmentSummary
    {
        $tenantId = $this->tenantContext->getTenantId();
        $this->assertNotNull($tenantId);

        $this->em->clear();

        $summary = $this->summaryRepository->findByInvestment($investmentId, $tenantId);
        $this->assertNotNull($summary, 'El InvestmentSummary debe estar persistido.');

        return $summary;
    }

    /**
     * @return array{investmentId: int, saleId: int, userId: int, tenantId: int}
     */
    private function seedConfirmedInvestmentWithSale(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $item = $this->itemService->addItem($investment, $product, 10, '5.00');
        $this->em->flush();
        $this->confirmInvestment($investment);

        $sale = $this->saleService->register(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [new RegisterSaleLineCommand($item->getId(), 2, '10.00')],
            ),
            $user
        );

        return [
            'investmentId' => $investment->getId(),
            'saleId' => $sale->getId(),
            'userId' => $user->getId(),
            'tenantId' => $tenant->getId(),
        ];
    }
}
