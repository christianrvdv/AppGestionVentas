<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Investment;
use App\Entity\UsdRate;

/**
 * Smoke test del dashboard (Fase 1 UI).
 *
 * DoD: con sesión iniciada, GET / responde 200 y contiene "Dashboard".
 * Verifica además que los contadores y la tasa se pintan con datos
 * reales del tenant (no placeholders).
 */
final class DashboardSmokeTest extends FunctionalTestCase
{
    public function testDashboardRendersForAuthenticatedUser(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->followRedirect();

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Dashboard', $response->getContent());
    }

    public function testDashboardShowsTenantDataAndRate(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user] = $this->seedDemoTenantAndAdmin();

        $rate = new UsdRate();
        $rate->setTenant($tenant);
        $rate->setRateDate(new \DateTimeImmutable('2026-10-01'));
        $rate->setRate('1500.0000');
        $this->em->persist($rate);

        $investment = new Investment();
        $investment->setTenant($tenant);
        $investment->setCreatedBy($user);
        $investment->setCode('INV-0001');
        $investment->setInvestmentDate(new \DateTimeImmutable('2026-10-01'));
        $investment->setBaseCurrency('ARS');
        $investment->setStatus(Investment::STATUS_OPEN);
        $this->em->persist($investment);

        $this->em->flush();

        $this->loginAsDemoAdmin($client);
        $client->followRedirect();
        $content = $client->getResponse()->getContent();

        $this->assertStringContainsString('INV-0001', $content);
        $this->assertStringContainsString('1.500,0000', $content);
        $this->assertStringContainsString('Total invertido', $content);
    }
}
