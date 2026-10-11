<?php

declare(strict_types=1);

namespace App\Tests\Integration\Report;

use App\DTO\Report\InvestmentReportFilters;
use App\Entity\Investment;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Report\InvestmentReportService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Smoke test para el reporte de inversiones.
 *
 * Verifica que el service genera datos correctamente con filtros y ordenación.
 */
final class InvestmentReportTest extends IntegrationTestCase
{
    private InvestmentReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = static::getContainer()->get(InvestmentReportService::class);
    }

    private function createInvestmentWithSummary(
        \App\Entity\Tenant $tenant,
        \App\Entity\AppUser $user,
        string $code,
        string $status = Investment::STATUS_OPEN,
        float $totalInvestment = 1000.00,
        float $totalRecovered = 0.00,
        float $totalRecognizedProfit = 0.00,
        float $totalPending = 0.00,
        float $recoveryPct = 0.00
    ): Investment {
        $investment = $this->createInvestment($tenant, $user, $code);
        $investment->setStatus($status);
        $product = $this->createProduct($tenant, 'Producto '.$code);

        $item = static::getContainer()->get(InvestmentItemService::class)
            ->addItem($investment, $product, 10, '100.00');
        $this->em->flush();

        $confirmation = static::getContainer()->get(InvestmentConfirmationService::class);
        $confirmation->confirm($investment);
        $this->em->flush();

        // Set status AFTER confirmation (confirmation resets to OPEN)
        $investment->setStatus($status);
        $this->em->flush();

        // Manually set summary values for testing different states
        $summary = static::getContainer()->get(\App\Repository\InvestmentSummaryRepository::class)
            ->findByInvestment($investment->getId(), $tenant->getId());

        if ($summary !== null) {
            $summary->setTotalInvestment(number_format($totalInvestment, 2, '.', ''));
            $summary->setTotalRecovered(number_format($totalRecovered, 2, '.', ''));
            $summary->setTotalRecognizedProfit(number_format($totalRecognizedProfit, 2, '.', ''));
            $summary->setTotalPending(number_format($totalPending, 2, '.', ''));
            $summary->setRecoveryPct(number_format($recoveryPct, 2, '.', ''));
            $this->em->flush();
        }

        return $investment;
    }

    public function test_genera_reporte_inversiones_con_datos_basicos(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        // Inversión abierta sin recuperar
        $this->createInvestmentWithSummary($tenant, $user, 'INV-001', Investment::STATUS_OPEN, 1000.00, 0.00, 0.00, 1000.00, 0.00);

        // Inversión parcialmente recuperada
        $this->createInvestmentWithSummary($tenant, $user, 'INV-002', Investment::STATUS_PARTIAL, 2000.00, 500.00, 100.00, 1500.00, 25.00);

        // Inversión totalmente recuperada
        $this->createInvestmentWithSummary($tenant, $user, 'INV-003', Investment::STATUS_RECOVERED, 1500.00, 1500.00, 300.00, 0.00, 100.00);

        $filters = new InvestmentReportFilters();
        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertArrayHasKey('rows', $data);
        $this->assertCount(3, $data['rows']);

        // Verificar que los datos básicos están presentes
        foreach ($data['rows'] as $row) {
            $this->assertInstanceOf(Investment::class, $row['investment']);
            $this->assertArrayHasKey('summary', $row);
            $this->assertArrayHasKey('recoveryPct', $row);
            $this->assertArrayHasKey('progressClass', $row);
        }
    }

    public function test_filtro_por_estado_funciona(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->createInvestmentWithSummary($tenant, $user, 'INV-OPEN', Investment::STATUS_OPEN, 1000.00, 0.00, 0.00, 1000.00, 0.00);
        $this->createInvestmentWithSummary($tenant, $user, 'INV-PARTIAL', Investment::STATUS_PARTIAL, 1000.00, 500.00, 0.00, 500.00, 50.00);
        $this->createInvestmentWithSummary($tenant, $user, 'INV-RECOVERED', Investment::STATUS_RECOVERED, 1000.00, 1000.00, 200.00, 0.00, 100.00);

        $filters = new InvestmentReportFilters(status: Investment::STATUS_PARTIAL);
        $data = $this->service->generate($tenant->getId(), $filters);

        $this->assertCount(1, $data['rows']);
        $this->assertEquals(Investment::STATUS_PARTIAL, $data['rows'][0]['investment']->getStatus());
    }

    public function test_ordenacion_por_recovery_pct_desc(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->createInvestmentWithSummary($tenant, $user, 'INV-LOW', Investment::STATUS_PARTIAL, 1000.00, 100.00, 0.00, 900.00, 10.00);
        $this->createInvestmentWithSummary($tenant, $user, 'INV-MED', Investment::STATUS_PARTIAL, 1000.00, 500.00, 0.00, 500.00, 50.00);
        $this->createInvestmentWithSummary($tenant, $user, 'INV-HIGH', Investment::STATUS_RECOVERED, 1000.00, 1000.00, 0.00, 0.00, 100.00);

        $filters = new InvestmentReportFilters(sortBy: 'recoveryPct', sortDir: 'DESC');
        $data = $this->service->generate($tenant->getId(), $filters);

        $pcts = array_column($data['rows'], 'recoveryPct');
        $this->assertEquals(['100.00', '50.00', '10.00'], $pcts);
    }

    public function test_ordenacion_por_pending_asc(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->createInvestmentWithSummary($tenant, $user, 'INV-HIGH-PENDING', Investment::STATUS_OPEN, 1000.00, 0.00, 0.00, 1000.00, 0.00);
        $this->createInvestmentWithSummary($tenant, $user, 'INV-MED-PENDING', Investment::STATUS_PARTIAL, 1000.00, 500.00, 0.00, 500.00, 50.00);
        $this->createInvestmentWithSummary($tenant, $user, 'INV-LOW-PENDING', Investment::STATUS_RECOVERED, 1000.00, 1000.00, 0.00, 0.00, 100.00);

        $filters = new InvestmentReportFilters(sortBy: 'totalPending', sortDir: 'ASC');
        $data = $this->service->generate($tenant->getId(), $filters);

        $pendings = array_map(fn($row) => $row['summary']->getTotalPending(), $data['rows']);
        $this->assertEquals(['0.00', '500.00', '1000.00'], $pendings);
    }
}