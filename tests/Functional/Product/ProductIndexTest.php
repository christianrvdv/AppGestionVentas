<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;

final class ProductIndexTest extends FunctionalTestCase
{
    public function testIndexRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/productos');

        $this->assertResponseRedirects('/login');
    }

    public function testIndexPaginatesProducts(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();

        for ($i = 0; $i < 12; ++$i) {
            $this->seedProduct($tenant, "Producto {$i}");
        }

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos');

        $this->assertResponseIsSuccessful();
        $this->assertCount(10, $crawler->filter('table tbody tr'));
        $this->assertGreaterThan(0, $crawler->filter('nav[aria-label="Paginación de productos"] a[href*="page=2"]')->count());
    }

    public function testIndexFiltersByNameCaseInsensitively(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedProduct($tenant, 'Café en grano', 'CAFE-1');
        $this->seedProduct($tenant, 'Azúcar', 'AZU-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos?q=café');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', 'Café en grano');
    }

    public function testIndexFiltersBySkuCaseInsensitively(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedProduct($tenant, 'Café en grano', 'CAFE-1');
        $this->seedProduct($tenant, 'Azúcar', 'AZU-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos?q=cafe-1');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table tbody tr'));
        $this->assertSelectorTextContains('table tbody', 'Café en grano');
    }

    public function testIndexShowsEmptyMessageWhenFilterMatchesNothing(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedProduct($tenant, 'Café en grano', 'CAFE-1');

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/productos?q=inexistente');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'No hay productos que coincidan');
    }

    public function testIndexKeepsFilterOnPageTwo(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();

        for ($i = 0; $i < 12; ++$i) {
            $this->seedProduct($tenant, "Cafetera {$i}", "CAF-{$i}");
        }

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos?q=cafetera');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(
            0,
            $crawler->filter('nav[aria-label="Paginación de productos"] a[href*="page=2"][href*="q="]')->count()
        );
    }

    public function testIndexOnlyShowsCurrentTenantProducts(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedProduct($tenant, 'Producto del tenant demo', 'DEMO-1');

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

        $this->seedProduct($otherTenant, 'Producto ajeno', 'AJENO-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos');

        $this->assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        $this->assertStringContainsString('Producto del tenant demo', $body);
        $this->assertStringNotContainsString('Producto ajeno', $body);
    }
}
