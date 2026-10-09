<?php

declare(strict_types=1);

namespace App\Tests\Functional\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentSummary;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Tests\Functional\FunctionalTestCase;

final class InvestmentCrudTest extends FunctionalTestCase
{
    public function testCreateInvestmentFlow(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/inversiones/nueva');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Crear inversión')->form();
        $client->submit($form, [
            'investment_form' => [
                'investmentDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'description' => 'Compra de mercadería',
            ],
        ]);

        $this->assertResponseRedirects();
        $investment = $this->em->getRepository(Investment::class)->findOneBy(['description' => 'Compra de mercadería']);
        $this->assertNotNull($investment);
        $this->assertSame(Investment::STATUS_OPEN, $investment->getStatus());
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{3}$/', $investment->getCode());

        $crawler = $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', $investment->getCode());
    }

    public function testCreateRejectsFutureDate(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/inversiones/nueva');
        $form = $crawler->selectButton('Crear inversión')->form();
        $client->submit($form, [
            'investment_form' => [
                'investmentDate' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
                'description' => null,
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'La fecha no puede ser futura.');
    }

    public function testShowRendersItems(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user, null, 'Con ítems');
        $product = $this->seedProduct($tenant, 'Café en grano', 'CAFE-1');
        $this->addItemToInvestment($investment, $product, 2, '500.00');

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/inversiones/'.$investment->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('table', 'Café en grano');
    }

    public function testShowForeignInvestmentReturns404(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        $foreignTenant = new \App\Entity\Tenant();
        $foreignTenant->setName('Ajeno');
        $foreignTenant->setSlug('ajeno');
        $foreignUser = new \App\Entity\AppUser();
        $foreignUser->setEmail('admin@ajeno.local');
        $foreignUser->setRoles(['ROLE_ADMIN']);
        $foreignUser->setPasswordHash('x');
        $foreignMembership = new \App\Entity\TenantMembership($foreignUser, $foreignTenant);
        $foreignUser->addMembership($foreignMembership);
        $this->em->persist($foreignTenant);
        $this->em->persist($foreignUser);
        $this->em->persist($foreignMembership);
        $this->em->flush();

        $foreign = $this->seedInvestment($foreignUser, null, 'Ajena');

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/inversiones/'.$foreign->getId());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEditOpenInvestment(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user, null, 'Original');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId().'/editar');
        $form = $crawler->selectButton('Guardar cambios')->form();
        $client->submit($form, [
            'investment_form' => [
                'investmentDate' => $investment->getInvestmentDate()->format('Y-m-d'),
                'description' => 'Editada',
            ],
        ]);

        $this->assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertSame('Editada', $reloaded->getDescription());
    }

    public function testEditConfirmedInvestmentIsRejected(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Producto confirmado');
        $this->addItemToInvestment($investment, $product);

        $context = $client->getContainer()->get(\App\Service\TenantContext::class);
        $context->setTenantId($tenant->getId());
        $client->getContainer()->get(\App\Service\Investment\InvestmentConfirmationService::class)->confirm($investment);
        $this->em->flush();
        $context->clear();

        $this->loginAsDemoAdmin($client);
        $client->request('GET', '/inversiones/'.$investment->getId().'/editar');

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
    }

    public function testAddItemRecalculatesTotals(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Azúcar', 'AZU-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Agregar ítem')->form();
        $client->submit($form, [
            'investment_item_form' => [
                'product' => $product->getId(),
                'quantity' => 3,
                'unitCost' => 250.50,
            ],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(1, $reloaded->getItems());
        $item = $reloaded->getItems()->first();
        $this->assertSame(3, $item->getQuantity());
        $this->assertDecimalEquals('250.50', $item->getUnitCost());
    }

    public function testAddItemRejectsZeroQuantity(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Pan', 'PAN-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Agregar ítem')->form();
        $client->submit($form, [
            'investment_item_form' => [
                'product' => $product->getId(),
                'quantity' => 0,
                'unitCost' => 10,
            ],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'inválidos');
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(0, $reloaded->getItems());
    }

    public function testConfirmFlowMaterializesTotals(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Yerba', 'YER-1');
        $this->addItemToInvestment($investment, $product, 2, '100.00');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Confirmar inversión')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertNotNull($reloaded->getConfirmedAt());
        $this->assertDecimalEquals('200.00', $reloaded->getTotalInvestment());

        $summary = $this->em->getRepository(InvestmentSummary::class)->findOneBy(['investment' => $reloaded]);
        $this->assertNotNull($summary);
    }

    public function testConfirmWithoutItemsShowsErrorFlash(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->filter('form[action*="/confirmar"]')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'al menos un ítem');
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertNull($reloaded->getConfirmedAt());
    }

    public function testCancelFlowPersistsReason(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Cancelar inversión')->form();
        $client->submit($form, [
            'investment_cancel_form' => ['reason' => 'El proveedor anuló el envío'],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertSame(Investment::STATUS_CANCELLED, $reloaded->getStatus());
        $this->assertSame('El proveedor anuló el envío', $reloaded->getCancelledReason());
    }

    public function testCancelWithoutReasonShowsError(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Cancelar inversión')->form();
        $client->submit($form, [
            'investment_cancel_form' => ['reason' => ''],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'motivo');
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertSame(Investment::STATUS_OPEN, $reloaded->getStatus());
    }
}
