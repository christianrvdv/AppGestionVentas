<?php

declare(strict_types=1);

namespace App\Tests\Functional\Product;

use App\Entity\AppUser;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;

final class ProductCrudTest extends FunctionalTestCase
{
    public function testCreateProductFlow(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/productos/nuevo');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Crear producto')->form();
        $client->submit($form, [
            'product_form' => [
                'name' => 'Café en grano',
                'sku' => 'CAF-500',
                'category' => 'Bebidas',
                'defaultMarginPct' => '30.50',
                'isActive' => 1,
            ],
        ]);

        $this->assertResponseRedirects('/productos');
        $this->em->clear();

        $product = $this->em->getRepository(Product::class)->findOneBy(['sku' => 'CAF-500']);
        $this->assertNotNull($product);
        $this->assertSame('Café en grano', $product->getName());
        $this->assertSame('Bebidas', $product->getCategory());
        $this->assertTrue($product->isActive());
        $this->assertSame($tenant->getId(), $product->getTenant()->getId());
        $this->assertDecimalEquals('30.50', (string) $product->getDefaultMarginPct());
    }

    public function testCreateRejectsMissingName(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/productos/nuevo');
        $form = $crawler->selectButton('Crear producto')->form();
        $client->submit($form, [
            'product_form' => [
                'name' => '',
                'sku' => 'SIN-NOMBRE',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El nombre es obligatorio.');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Product::class)->findOneBy(['sku' => 'SIN-NOMBRE']));
    }

    public function testCreateRejectsMarginOutOfRange(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/productos/nuevo');
        $form = $crawler->selectButton('Crear producto')->form();
        $client->submit($form, [
            'product_form' => [
                'name' => 'Margen imposible',
                'sku' => 'MARGEN-150',
                'defaultMarginPct' => '150',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El margen debe estar entre 0 y 100%.');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Product::class)->findOneBy(['sku' => 'MARGEN-150']));
    }

    public function testCreateRejectsDuplicateSkuInSameTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedProduct($tenant, 'Café original', 'CAFE-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos/nuevo');
        $form = $crawler->selectButton('Crear producto')->form();
        $client->submit($form, [
            'product_form' => [
                'name' => 'Café duplicado',
                'sku' => 'CAFE-1',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El SKU "CAFE-1" ya está en uso en este negocio.');

        $this->em->clear();
        $products = $this->em->getRepository(Product::class)->findBy(['sku' => 'CAFE-1']);
        $this->assertCount(1, $products);
        $this->assertSame('Café original', $products[0]->getName());
    }

    public function testCreateAllowsSameSkuInAnotherTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $foreign = $this->seedForeignTenant();
        $this->seedProduct($foreign['tenant'], 'Café ajeno', 'CAFE-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos/nuevo');
        $form = $crawler->selectButton('Crear producto')->form();
        $client->submit($form, [
            'product_form' => [
                'name' => 'Café repetido en el demo',
                'sku' => 'CAFE-1',
            ],
        ]);

        $this->assertResponseRedirects('/productos');
        $this->em->clear();

        $demoProducts = $this->em->getRepository(Product::class)->findBy(['tenant' => $tenant]);
        $this->assertCount(1, $demoProducts);
        $this->assertSame('CAFE-1', $demoProducts[0]->getSku());
    }

    public function testCreateStoresOptionalFieldsAsNullAndAllowsRepeatedNullSku(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $this->seedProduct($tenant, 'Sin SKU', null);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos/nuevo');
        $form = $crawler->selectButton('Crear producto')->form();
        $client->submit($form, [
            'product_form' => [
                'name' => 'Otro sin SKU',
                'sku' => '',
                'category' => '',
                'defaultMarginPct' => '',
            ],
        ]);

        $this->assertResponseRedirects('/productos');
        $this->em->clear();

        $created = $this->em->getRepository(Product::class)->findOneBy(['name' => 'Otro sin SKU']);
        $this->assertNotNull($created);
        $this->assertNull($created->getSku());
        $this->assertNull($created->getCategory());
        $this->assertNull($created->getDefaultMarginPct());
        $this->assertCount(2, $this->em->getRepository(Product::class)->findAll());
    }

    public function testEditProduct(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $product = $this->seedProduct($tenant, 'Café en grano', 'CAFE-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos/'.$product->getId().'/editar');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar cambios')->form();
        $client->submit($form, [
            'product_form' => [
                'name' => 'Café en grano molido',
                'sku' => 'CAFE-1',
                'category' => 'Bebidas calientes',
                'defaultMarginPct' => '25',
                'isActive' => 1,
            ],
        ]);

        $this->assertResponseRedirects('/productos');
        $this->em->clear();

        $reloaded = $this->em->getRepository(Product::class)->find($product->getId());
        $this->assertSame('Café en grano molido', $reloaded->getName());
        $this->assertSame('Bebidas calientes', $reloaded->getCategory());
        $this->assertDecimalEquals('25.00', (string) $reloaded->getDefaultMarginPct());
    }

    public function testEditForeignProductReturns404(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $foreign = $this->seedForeignTenant();
        $foreignProduct = $this->seedProduct($foreign['tenant'], 'Producto ajeno', 'AJENO-1');

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/productos/'.$foreignProduct->getId().'/editar');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeactivateProduct(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $product = $this->seedProduct($tenant, 'Café en grano', 'CAFE-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/productos');
        $form = $crawler->filter('form[action*="/desactivar"]')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/productos');
        $this->em->clear();

        $reloaded = $this->em->getRepository(Product::class)->find($product->getId());
        $this->assertFalse($reloaded->isActive());

        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'desactivado');
    }

    public function testDeactivateRejectsInvalidCsrfToken(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $product = $this->seedProduct($tenant, 'Café en grano', 'CAFE-1');

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/productos/'.$product->getId().'/desactivar', ['_csrf_token' => 'no-es-un-token']);

        $this->assertResponseRedirects('/productos');
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'Solicitud inválida.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Product::class)->find($product->getId());
        $this->assertTrue($reloaded->isActive());
    }

    public function testDeactivateUnknownProductReturns404(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->request('POST', '/productos/999999/desactivar');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{tenant: Tenant, user: AppUser}
     */
    private function seedForeignTenant(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Ajeno');
        $tenant->setSlug('ajeno');

        $user = new AppUser();
        $user->setTenant($tenant);
        $user->setEmail('admin@ajeno.local');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPasswordHash('x');

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->flush();

        return ['tenant' => $tenant, 'user' => $user];
    }
}
