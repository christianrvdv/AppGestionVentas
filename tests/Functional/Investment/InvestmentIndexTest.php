<?php

declare(strict_types=1);

namespace App\Tests\Functional\Investment;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Service\Investment\InvestmentStateMachine;
use App\Tests\Functional\FunctionalTestCase;

final class InvestmentIndexTest extends FunctionalTestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/inversiones');

        $this->assertResponseRedirects('/login');
    }

    public function testIndexPaginatesInvestments(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        for ($i = 0; $i < 12; ++$i) {
            $this->seedInvestment($user, null, "Inversión {$i}");
        }

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones');

        $this->assertResponseIsSuccessful();
        $this->assertCount(10, $crawler->filter('table tbody tr'));
        $this->assertGreaterThan(0, $crawler->filter('nav[aria-label="Paginación de inversiones"] a[href*="page=2"]')->count());
    }

    public function testIndexFiltersByStatus(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        $this->seedInvestment($user, null, 'Abierta para filtro');
        $cancelled = $this->seedInvestment($user, null, 'Cancelada para filtro');

        $context = $client->getContainer()->get(\App\Service\TenantContext::class);
        $context->setTenantId($this->tenantIdOf($user));
        $client->getContainer()->get(InvestmentStateMachine::class)->cancel($cancelled, 'No procede');
        $this->em->flush();
        $context->clear();

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones?status=CANCELLED');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', $cancelled->getCode());
    }

    public function testIndexOnlyShowsCurrentTenantInvestments(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->seedInvestment($user, null, 'Inversión tenant demo');

        $otherTenant = new Tenant();
        $otherTenant->setName('Otro');
        $otherTenant->setSlug('otro');
        $otherUser = new AppUser();
        $otherUser->setEmail('admin@otro.local');
        $otherUser->setRoles(['ROLE_ADMIN']);
        $otherUser->setPasswordHash('x');
        $otherMembership = new TenantMembership($otherUser, $otherTenant);
        $otherUser->addMembership($otherMembership);
        $this->em->persist($otherTenant);
        $this->em->persist($otherUser);
        $this->em->persist($otherMembership);
        $this->em->flush();

        $this->seedInvestment($otherUser, null, 'Inversión ajena');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones');

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Inversión tenant demo', $body);
        $this->assertStringNotContainsString('Inversión ajena', $body);
    }
}
