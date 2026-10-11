<?php

namespace App\Tests\Integration\Investment;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Entity\UsdRate;
use App\Repository\Contract\ItemCostRevaluationRepositoryInterface;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentCreationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Investment\InvestmentRevaluationService;
use App\Service\UsdRateService;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class InvestmentRevaluationServiceTest extends IntegrationTestCase
{
    private InvestmentRevaluationService $revaluationService;
    private UsdRateService $usdRateService;
    private InvestmentConfirmationService $confirmationService;
    private InvestmentCreationService $creationService;
    private InvestmentItemService $itemService;
    private ItemCostRevaluationRepositoryInterface $revaluationRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->revaluationService = $container->get(InvestmentRevaluationService::class);
        $this->usdRateService = $container->get(UsdRateService::class);
        $this->confirmationService = $container->get(InvestmentConfirmationService::class);
        $this->creationService = $container->get(InvestmentCreationService::class);
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->revaluationRepository = $container->get(ItemCostRevaluationRepositoryInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
    }

    /**
     * Crea una inversión confirmada con ítems y stock para poder revaluar.
     */
    private function createConfirmedInvestmentWithItems(AppUser $user): Investment
    {
        $tenant = $user->getMemberships()->first()->getTenant();
        $this->tenantContext->setTenantId($tenant->getId());

        $investment = $this->creationService->createDraft($user, new \DateTimeImmutable('2026-01-01'), 'Test');
        $this->em->persist($investment);

        $product = new Product();
        $product->setTenant($tenant);
        $product->setName('Producto Test');
        $product->setSku('SKU-TEST');
        $product->setDefaultMarginPct('40.00');
        $this->em->persist($product);
        $this->em->flush();

        $this->itemService->addItem($investment, $product, 100, '100.00');
        $this->em->flush();

        $this->confirmationService->confirm($investment);
        $this->em->flush();

        return $investment;
    }

    public function test_revalue_open_investment_creates_revaluation_and_updates_cost(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        // Registrar tasa inicial (snapshot al confirmar)
        $initialRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user,
            'tasa inicial'
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Registrar nueva tasa vigente
        $newRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user,
            'nueva tasa'
        );

        // Revaluar
        $this->revaluationService->revalue($investment, $newRate, $user);
        $this->em->flush();

        // Verificar que se creó ItemCostRevaluation
        $revaluations = $this->revaluationRepository->findByInvestment($investment->getId(), $tenant->getId());
        $this->assertCount(1, $revaluations);

        $rev = $revaluations[0];
        $this->assertSame('350.0000', $rev->getOldRate());
        $this->assertSame('400.0000', $rev->getNewRate());
        $this->assertSame('100.00', $rev->getOldRealUnitCost());
        $this->assertGreaterThan('100.00', $rev->getNewRealUnitCost());
        $this->assertSame(100, $rev->getQuantitySnapshot());

        // Verificar que currentRealUnitCost se actualizó
        $investment = $this->reload($investment);
        $item = $investment->getItems()->first();
        $this->assertSame($rev->getNewRealUnitCost(), $item->getCurrentRealUnitCost());

        // Verificar que currentUsdRate de la inversión se actualizó
        $this->assertSame('400.0000', $investment->getCurrentUsdRate());
    }

    public function test_revalue_closed_investment_throws_exception(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $initialRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Cerrar la inversión
        $stateMachine = static::getContainer()->get(\App\Service\Investment\InvestmentStateMachine::class);
        $stateMachine->close($investment);
        $this->em->flush();

        $newRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No se puede revaluar inversión en estado CLOSED');

        $this->revaluationService->revalue($investment, $newRate, $user);
    }

    public function test_revalue_cancelled_investment_throws_exception(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $initialRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Cancelar la inversión
        $stateMachine = static::getContainer()->get(\App\Service\Investment\InvestmentStateMachine::class);
        $stateMachine->cancel($investment, 'Cancelada para test');
        $this->em->flush();

        $newRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No se puede revaluar inversión en estado CANCELLED');

        $this->revaluationService->revalue($investment, $newRate, $user);
    }

    public function test_revalue_without_current_rate_throws_exception(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        // NO registrar tasa inicial
        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Crear una tasa pero no como vigente (fecha anterior)
        $rate = new UsdRate();
        $rate->setTenant($tenant);
        $rate->setRateDate(new \DateTimeImmutable('2025-01-01'));
        $rate->setRate('300.0000');
        $rate->setCreatedBy($user);
        $this->em->persist($rate);
        $this->em->flush();

        // La inversión no tiene currentUsdRate
        $this->assertNull($investment->getCurrentUsdRate());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('La inversión no tiene tasa USD previa para revaluar');

        $this->revaluationService->revalue($investment, $rate, $user);
    }

    public function test_revalue_same_rate_does_nothing(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $initialRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user
        );

        $investment = $this->createConfirmedInvestmentWithItems($user);

        // Misma tasa - no debe crear revaluación
        $sameRate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '350.0000',
            $user
        );

        $this->revaluationService->revalue($investment, $sameRate, $user);
        $this->em->flush();

        $revaluations = $this->revaluationRepository->findByInvestment($investment->getId(), $tenant->getId());
        $this->assertCount(0, $revaluations);
    }

    public function test_tenant_isolation_on_revaluation(): void
    {
        $tenant1 = $this->createTenant('tenant1');
        $user1 = $this->createAppUser($tenant1, 'user1@test.com');

        $tenant2 = $this->createTenant('tenant2');
        $user2 = $this->createAppUser($tenant2, 'user2@test.com');

        // Crear inversión en tenant1
        $this->tenantContext->setTenantId($tenant1->getId());
        $initialRate1 = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-01-01'),
            '350.0000',
            $user1
        );
        $investment1 = $this->createConfirmedInvestmentWithItems($user1);
        $investment1Id = $investment1->getId();

        // Crear tasa en tenant2
        $this->tenantContext->setTenantId($tenant2->getId());
        $rate2 = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '400.0000',
            $user2
        );

        // Intentar revaluar inversión de tenant1 con tasa de tenant2
        $this->expectException(\App\Exception\Domain\TenantMismatchException::class);

        $this->revaluationService->revalue($investment1, $rate2, $user2);
    }
}