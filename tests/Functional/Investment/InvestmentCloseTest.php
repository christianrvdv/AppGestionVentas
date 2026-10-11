<?php

declare(strict_types=1);

namespace App\Tests\Functional\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentItemService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class InvestmentCloseTest extends FunctionalTestCase
{
    public function testCloseFromOpenSetsClosedAt(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $investment = $this->seedInvestment($this->getDemoUser(), null, 'Para cerrar desde OPEN');

        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Cerrar inversión')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Inversión cerrada.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertSame(Investment::STATUS_CLOSED, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getClosedAt());
    }

    public function testCloseFromPartial(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user, null, 'Para cerrar desde PARTIAL');
        $product = $this->seedProduct($tenant, 'Producto Partial');
        $this->addItemToInvestment($investment, $product, 10, '100.00');

        $this->runAsTenant(
            $tenant->getId(),
            function () use ($investment): void {
                static::getContainer()->get(InvestmentConfirmationService::class)->confirm($investment);
                $this->em->flush();
            }
        );

        $this->runAsTenant(
            $tenant->getId(),
            function () use ($investment): void {
                static::getContainer()->get(\App\Service\Investment\InvestmentStateMachine::class)->markPartial($investment);
                $this->em->flush();
            }
        );

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Parcial');

        $form = $crawler->selectButton('Cerrar inversión')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Inversión cerrada.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertSame(Investment::STATUS_CLOSED, $reloaded->getStatus());
    }

    public function testCloseFromRecovered(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user, null, 'Para cerrar desde RECOVERED');
        $product = $this->seedProduct($tenant, 'Producto Recovered');
        $this->addItemToInvestment($investment, $product, 10, '100.00');

        $this->runAsTenant(
            $tenant->getId(),
            function () use ($investment): void {
                static::getContainer()->get(InvestmentConfirmationService::class)->confirm($investment);
                $this->em->flush();
            }
        );

        $customer = $this->seedCustomer($tenant, 'Cliente para venta');
        $this->seedSale($investment, $user, $customer, quantity: 10, unitPrice: '200.00');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Recuperada');

        $form = $crawler->selectButton('Cerrar inversión')->form();
        $client->submit($form);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Inversión cerrada.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertSame(Investment::STATUS_CLOSED, $reloaded->getStatus());
    }

    public function testCloseAlreadyClosedIsIdempotent(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $investment = $this->seedInvestment($this->getDemoUser(), null, 'Ya cerrada');

        // Obtener token CSRF válido ANTES de cerrar (desde el formulario de confirmar)
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $csrfToken = $crawler->filter('form[action*="/confirmar"] input[name$="[_token]"]')->attr('value');
        $this->assertNotNull($csrfToken);

        // Cerrar vía web (simula cierre previo real)
        $client->request('POST', '/inversiones/'.$investment->getId().'/cerrar', [
            'form' => ['_token' => $csrfToken],
        ]);
        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $client->followRedirect();

        // Limpiar entity manager para forzar consulta fresca
        $this->em->clear();

        // El botón no debería aparecer para inversiones ya cerradas
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('button:contains("Cerrar inversión")');

        // Reutilizar el mismo token CSRF (token ID "form") para la segunda llamada idempotente
        // (el token sigue siendo válido en la sesión)
        $client->request('POST', '/inversiones/'.$investment->getId().'/cerrar', [
            'form' => ['_token' => $csrfToken],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        // Verificar flash de éxito (role="status" con clases success) y no el banner (brand)
        $this->assertSelectorTextContains('div[role="status"].text-success', 'Inversión cerrada.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertSame(Investment::STATUS_CLOSED, $reloaded->getStatus());
        $this->assertNotNull($reloaded->getClosedAt());
    }

    public function testCloseCancelledShowsErrorFlash(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $investment = $this->seedInvestment($this->getDemoUser(), null, 'Cancelada');

        // Obtener token CSRF válido ANTES de cancelar (desde el formulario de confirmar)
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $csrfToken = $crawler->filter('form[action*="/confirmar"] input[name$="[_token]"]')->attr('value');
        $this->assertNotNull($csrfToken);

        // Cancelar vía web (simula cancelación previa real)
        // El formulario de cancelar usa investment_cancel_form[_token] para CSRF
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $cancelCsrfToken = $crawler->filter('form[action*="/cancelar"] input[name$="[_token]"]')->attr('value');
        $this->assertNotNull($cancelCsrfToken);
        
        $client->request('POST', '/inversiones/'.$investment->getId().'/cancelar', [
            'investment_cancel_form' => [
                'reason' => 'Motivo test',
                '_token' => $cancelCsrfToken,
            ],
        ]);
        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $client->followRedirect();

        // Limpiar entity manager para forzar consulta fresca
        $this->em->clear();

        // El botón no debería aparecer para inversiones canceladas
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('button:contains("Cerrar inversión")');

        // Obtener token CSRF válido del formulario de nueva inversión (token ID "form" en investment_form)
        $crawler = $client->request('GET', '/inversiones/nueva');
        $csrfToken2 = $crawler->filter('form input[name$="[_token]"]')->attr('value');
        $this->assertNotNull($csrfToken2);

        // Enviar POST directo al endpoint con CSRF válido
        $client->request('POST', '/inversiones/'.$investment->getId().'/cerrar', [
            'form' => ['_token' => $csrfToken2],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'transicionar desde estado terminal CANCELLED a CLOSED');
    }

    public function testCloseWithInvalidCsrfReturns403(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $investment = $this->seedInvestment($this->getDemoUser(), null, 'Para CSRF');

        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertResponseIsSuccessful();

        // Obtener token CSRF válido del formulario de confirmar (mismo token ID "form")
        $csrfToken = $crawler->filter('form[action*="/confirmar"] input[name$="[_token]"]')->attr('value');
        $this->assertNotNull($csrfToken);

        // Enviar POST con token inválido en el formato correcto (form[_token])
        $client->request('POST', '/inversiones/'.$investment->getId().'/cerrar', [
            'form' => ['_token' => 'invalid-token'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCloseForeignInvestmentReturns404(): void
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

        // Obtener token CSRF válido desde una inversión propia
        $ownInvestment = $this->seedInvestment($this->getDemoUser(), null, 'Propia');
        $crawler = $client->request('GET', '/inversiones/'.$ownInvestment->getId());
        $csrfToken = $crawler->filter('form[action*="/confirmar"] input[name$="[_token]"]')->attr('value');
        $this->assertNotNull($csrfToken);

        $client->request('POST', '/inversiones/'.$foreign->getId().'/cerrar', [
            'form' => ['_token' => $csrfToken],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    private function getDemoUser(): \App\Entity\AppUser
    {
        return $this->em->getRepository(\App\Entity\AppUser::class)->findOneBy(['email' => 'admin@demo.local']);
    }
}