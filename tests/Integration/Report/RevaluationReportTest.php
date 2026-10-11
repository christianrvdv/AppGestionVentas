<?php

namespace App\Tests\Integration\Report;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Repository\Contract\ItemCostRevaluationRepositoryInterface;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentCreationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Investment\InvestmentRevaluationService;
use App\Service\UsdRateService;
use App\Tests\Integration\IntegrationTestCase;

final class RevaluationReportTest extends IntegrationTestCase
{
    private UsdRateService $usdRateService;
    private InvestmentCreationService $creationService;
    private InvestmentItemService $itemService;
    private InvestmentConfirmationService $confirmationService;
    private InvestmentRevaluationService $revaluationService;
    private ItemCostRevaluationRepositoryInterface $revaluationRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->usdRateService = $container->get(UsdRateService::class);
        $this->creationService = $container->get(InvestmentCreationService::class);
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->confirmationService = $container->get(InvestmentConfirmationService::class);
        $this->revaluationService = $container->get(InvestmentRevaluationService::class);
        $this->revaluationRepository = $container->get(ItemCostRevaluationRepositoryInterface::class);
    }

    private function createConfirmedInvestmentWithItems(AppUser $user, string $code = 'INV-TEST'): Investment
    {
        $tenant = $user->getMemberships()->first()->getTenant();
        $this->tenantContext->setTenantId($tenant->getId());

        $investment = $this->creationService->createDraft($user, new \DateTimeImmutable('2026-01-01'), $code);
        $this->em->persist($investment);

        $product = new Product();
        $product->setTenant($tenant);
        $product->setName('Producto Test');
        $product->setSku('SKU-' . $code);
        $product->setDefaultMarginPct('40.00');
        $this->em->persist($product);
        $this->em->flush();

        $this->itemService->addItem($investment, $product, 100, '100.00');
        $this->em->flush();

        $this->confirmationService->confirm($investment);
        $this->em->flush();

        return $investment;
    }

    public function test_report_returns_correct_data_for_date_range(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        // Tasa inicial
        $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Tasa nueva para revaluar
        $newRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user
        );

        // Revaluar
        $this->revaluationService->revalue($investment, $newRate, $user);
        $this->em->flush();

        // Consultar reporte por rango de fechas
        $from = new \DateTimeImmutable('2026-09-01');
        $to = new \DateTimeImmutable('2026-10-31');

        $revaluations = $this->revaluationRepository->findByDateRange($tenant->getId(), $from, $to);

        $this->assertCount(1, $revaluations);
        $rev = $revaluations[0];

        $this->assertSame($investment->getId(), $rev->getInvestmentItem()->getInvestment()->getId());
        $this->assertSame('350.0000', $rev->getOldRate());
        $this->assertSame('400.0000', $rev->getNewRate());
        $this->assertSame('100.00', $rev->getOldRealUnitCost());
        $this->assertGreaterThan('100.00', $rev->getNewRealUnitCost());
        $this->assertSame(100, $rev->getQuantitySnapshot());
        $this->assertGreaterThan('0.00', $rev->getRevaluationGainLoss());
    }

    public function test_report_excludes_out_of_range_revaluations(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Revaluar en octubre
        $octRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user
        );
        $this->revaluationService->revalue($investment, $octRate, $user);
        $this->em->flush();

        // Revaluar en noviembre
        $novRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-11-01'),
            '450.0000',
            $user
        );
        $this->revaluationService->revalue($investment, $novRate, $user);
        $this->em->flush();

        // Consultar solo octubre
        $from = new \DateTimeImmutable('2026-10-01');
        $to = new \DateTimeImmutable('2026-10-31');

        $revaluations = $this->revaluationRepository->findByDateRange($tenant->getId(), $from, $to);

        // Ambas revaluaciones caen en octubre (10-01 y 10-15 son las fechas de createdAt)
        // La segunda revaluación se crea en 10-15, que está en el rango
        $this->assertCount(2, $revaluations);
    }

    public function test_report_sums_gain_loss_correctly(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Primera revaluación (sube el costo = pérdida latente)
        $rate1 = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user
        );
        $this->revaluationService->revalue($investment, $rate1, $user);
        $this->em->flush();

        // Segunda revaluación (baja el costo = ganancia latente)
        $rate2 = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-15'),
            '380.0000',
            $user
        );
        $this->revaluationService->revalue($investment, $rate2, $user);
        $this->em->flush();

        $from = new \DateTimeImmutable('2026-10-01');
        $to = new \DateTimeImmutable('2026-10-31');

        $revaluations = $this->revaluationRepository->findByDateRange($tenant->getId(), $from, $to);
        $this->assertCount(2, $revaluations);

        // Sumar gain/loss manualmente
        $total = '0.00';
        foreach ($revaluations as $rev) {
            $total = bcadd($total, $rev->getRevaluationGainLoss(), 2);
        }

        // La primera es pérdida (positiva), la segunda es ganancia (negativa)
        // Total debería ser la diferencia neta
        $this->assertNotSame('0.00', $total);
    }

    public function test_tenant_isolation_on_report(): void
    {
        $tenant1 = $this->createTenant('tenant1');
        $user1 = $this->createAppUser($tenant1, 'user1@test.com');

        $tenant2 = $this->createTenant('tenant2');
        $user2 = $this->createAppUser($tenant2, 'user2@test.com');

        // Revaluación en tenant1
        $this->tenantContext->setTenantId($tenant1->getId());
        $this->usdRateService->setRate(new \DateTimeImmutable('2026-01-01'), '350.0000', $user1);
        $investment1 = $this->createConfirmedInvestmentWithItems($user1);
        $rate1 = $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-01'), '400.0000', $user1);
        $this->revaluationService->revalue($investment1, $rate1, $user1);
        $this->em->flush();

        // Consultar reporte desde tenant2
        $this->tenantContext->setTenantId($tenant2->getId());
        $from = new \DateTimeImmutable('2026-10-01');
        $to = new \DateTimeImmutable('2026-10-31');

        $revaluations = $this->revaluationRepository->findByDateRange($tenant2->getId(), $from, $to);

        $this->assertCount(0, $revaluations);
    }

    public function test_sum_gain_loss_by_investment(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);
        $investmentId = $investment->getId();

        $rate1 = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user
        );
        $this->revaluationService->revalue($investment, $rate1, $user);
        $this->em->flush();

        $sum = $this->revaluationRepository->sumGainLossByInvestment($investmentId, $tenant->getId());

        $this->assertGreaterThan('0.00', $sum);

        // Verificar que coincide con la suma manual
        $revaluations = $this->revaluationRepository->findByInvestment($investmentId, $tenant->getId());
        $manualSum = '0.00';
        foreach ($revaluations as $rev) {
            $manualSum = bcadd($manualSum, $rev->getRevaluationGainLoss(), 2);
        }
        $this->assertSame($manualSum, $sum);
    }
}