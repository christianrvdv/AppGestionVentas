<?php

namespace App\Tests\Integration\Investment;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\Tenant;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Service\Investment\InvestmentItemService;
use App\Service\Investment\InvestmentSummaryService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Política de persistencia del summary creado por InvestmentSummaryService::recompute().
 *
 * recompute() crea el summary si no existe, pero solo lo persiste cuando la
 * inversión YA está confirmada:
 *
 *   - Confirmada sin summary (dato legado, summary borrado): el summary se
 *     persiste solo; el caller no puede "olvidarlo".
 *   - Borrador (confirmada sin confirmar): NO se persiste. Un summary de
 *     inversión en borrador tendría todos los totales en cero (Investment
 *     solo materializa totalInvestment al confirmar) y, sobre todo, chocaría
 *     con el índice único uniq_summary_investment: confirm() crea su propio
 *     InvestmentSummary y el INSERT secundario revienta (ADR-0017).
 *
 * Todos los asserts sobre BD leen con em->clear(): sin clear(), Doctrine
 * devuelve la instancia en memoria y el test pasaría aunque falte el persist.
 */
final class InvestmentSummaryRecomputePersistTest extends IntegrationTestCase
{
    private InvestmentItemService $itemService;

    private InvestmentSummaryService $summaryService;

    private InvestmentSummaryRepositoryInterface $summaryRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->summaryService = $container->get(InvestmentSummaryService::class);
        $this->summaryRepository = $container->get(InvestmentSummaryRepositoryInterface::class);
    }

    public function test_recompute_de_inversion_confirmada_sin_summary_lo_persiste(): void
    {
        [$tenant, , $investment] = $this->confirmedInvestment();

        // Dato legado / summary borrado: la inversión está confirmada y no tiene fila.
        $summary = $this->summaryRepository->findByInvestment($investment->getId(), $tenant->getId());
        $this->assertNotNull($summary, 'Precondición: la confirmación crea el summary.');
        $this->em->remove($summary);
        $this->em->flush();
        $this->em->clear();

        $investment = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->summaryService->recompute($investment);
        $this->em->flush();

        $this->em->clear();
        $reloaded = $this->summaryRepository->findByInvestment($investment->getId(), $tenant->getId());

        $this->assertNotNull($reloaded, 'recompute() debe persistir el summary cuando la inversión está confirmada.');
        $this->assertSame(10, $reloaded->getUnitsRemaining());
        $this->assertDecimalEquals('50.00', $reloaded->getTotalInvestment());
    }

    public function test_recompute_de_inversion_en_borrador_no_persiste_summary(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);
        $this->itemService->addItem($investment, $product, 10, '5.00');
        $this->em->flush();

        $summary = $this->summaryService->recompute($investment);
        $this->em->flush();

        $this->em->clear();
        $this->assertNull(
            $this->summaryRepository->findByInvestment($investment->getId(), $tenant->getId()),
            'Una inversión en borrador no debe generar summary: chocaría con uniq_summary_investment al confirmar.'
        );
    }

    /**
     * @return array{0: Tenant, 1: AppUser, 2: Investment}
     */
    private function confirmedInvestment(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $this->itemService->addItem($investment, $product, 10, '5.00');
        $this->em->flush();
        $this->confirmInvestment($investment);

        return [$tenant, $user, $investment];
    }
}
