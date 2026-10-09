<?php

declare(strict_types=1);

namespace App\Tests\Functional\Customer;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;

final class CustomerIndexTest extends FunctionalTestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/clientes');

        $this->assertResponseRedirects('/login');
    }

    public function testIndexPaginatesCustomers(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();

        for ($i = 0; $i < 12; ++$i) {
            $this->seedCustomer($tenant, "Cliente {$i}", sprintf('6000000%02d', $i));
        }

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes');

        $this->assertResponseIsSuccessful();
        $this->assertCount(10, $crawler->filter('table tbody tr'));
        $this->assertGreaterThan(0, $crawler->filter('nav[aria-label="Paginación de clientes"] a[href*="page=2"]')->count());
    }

    public function testIndexFiltersByNameCaseInsensitively(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');
        $this->seedCustomer($tenant, 'Juan López', '600333444', 'juan@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes?q=maría');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', 'María Pérez');
    }

    public function testIndexFiltersByPhone(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');
        $this->seedCustomer($tenant, 'Juan López', '600333444', 'juan@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes?q=333444');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', 'Juan López');
    }

    public function testIndexFiltersByEmailCaseInsensitively(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedCustomer($tenant, 'María Pérez', '600111222', 'maria@example.com');
        $this->seedCustomer($tenant, 'Juan López', '600333444', 'juan@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes?q=MARIA@EXAMPLE');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', 'María Pérez');
    }

    public function testIndexOnlyShowsCurrentTenantCustomers(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedCustomer($tenant, 'Cliente del tenant demo', '600000001', 'demo@example.com');

        $otherTenant = new Tenant();
        $otherTenant->setName('Otro');
        $otherTenant->setSlug('otro');
        $otherUser = new AppUser();
        $otherUser->setTenant($otherTenant);
        $otherUser->setEmail('admin@otro.local');
        $otherUser->setRoles(['ROLE_ADMIN']);
        $otherUser->setPasswordHash('x');
        $this->em->persist($otherTenant);
        $this->em->persist($otherUser);
        $this->em->flush();

        $this->seedCustomer($otherTenant, 'Cliente ajeno', '600000002', 'ajeno@example.com');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/clientes');

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Cliente del tenant demo', $body);
        $this->assertStringNotContainsString('Cliente ajeno', $body);
    }
}
