<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Entity\Investment;
use App\Entity\InvestmentSummary;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Service\Inventory\LossService;
use App\Service\Investment\InvestmentItemService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [ADR-0017] Política de flush en servicios que recalculan el summary.
 *
 * LossService::register() persistía el InventoryMovement y llamaba a
 * recompute() SIN flush intermedio. recompute() lee el stock con SUM()
 * DQL (InventoryMovementRepository::getStockByInvestment y
 * getLostQuantityByInvestment), y Doctrine no autocommitea antes de una
 * consulta: la merma recién creada era invisible para su propio
 * recálculo, así que unitsRemaining y unitsLost quedaban con los valores
 * anteriores.
 *
 * El test limpia el EntityManager antes de leer: solo pasa lo que está
 * realmente en la base de datos.
 */
final class LossServiceFlushTest extends IntegrationTestCase
{
    private InvestmentItemService $itemService;

    private LossService $lossService;

    private InvestmentSummaryRepositoryInterface $summaryRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->lossService = $container->get(LossService::class);
        $this->summaryRepository = $container->get(InvestmentSummaryRepositoryInterface::class);
    }

    public function test_registrar_merma_sin_flush_externo_deja_el_summary_correcto_en_bd(): void
    {
        [$tenant, $investment, $item] = $this->investmentWithStock(10);

        $this->lossService->register(
            $item,
            3,
            LossService::REASON_DAMAGE,
            new \DateTimeImmutable('2026-01-15'),
            'Rotura en almacén'
        );

        // Sin flush del lado del test y con el EM limpio: solo sobrevive lo persistido.
        $this->em->clear();
        $summary = $this->summaryFromDatabase($tenant->getId(), $investment->getId());

        $this->assertSame(3, $summary->getUnitsLost(), 'La merma debe sumar 3 unidades perdidas.');
        $this->assertSame(7, $summary->getUnitsRemaining(), 'Quedan 7 de 10 unidades.');
        $this->assertSame(0, $summary->getUnitsSold());
        // totalInvestment NO baja: la merma se informa en unitsLost, no
        // desinvirtiendo capital.
        $this->assertDecimalEquals('50.00', $summary->getTotalInvestment());
        $this->assertDecimalEquals('0.00', $summary->getTotalRecovered());
    }

    private function summaryFromDatabase(int $tenantId, int $investmentId): InvestmentSummary
    {
        $this->em->clear();

        $summary = $this->summaryRepository->findByInvestment($investmentId, $tenantId);
        $this->assertNotNull($summary, 'El InvestmentSummary debe estar persistido.');

        return $summary;
    }

    /**
     * @return array{\App\Entity\Tenant, Investment, \App\Entity\InvestmentItem}
     */
    private function investmentWithStock(int $quantity): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $item = $this->itemService->addItem($investment, $product, $quantity, '5.00');
        $this->em->flush();
        $this->confirmInvestment($investment);

        return [$tenant, $investment, $item];
    }
}
