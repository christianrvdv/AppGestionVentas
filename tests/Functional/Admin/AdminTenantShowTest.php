<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Detalle y activación/desactivación de un negocio (Fase 6.1,
 * escenarios 3 y 7).
 *
 * /admin/tenants/{id} muestra las métricas del negocio y sus miembros.
 * Las cifras se calculan con el tenantId EXPLÍCITO en la consulta (sin
 * SQLFilter para el super_admin), así que nada de otro negocio puede
 * colarse: el propio test lo afirma comparando la ficha de Demo con la
 * de Ajeno.
 *
 * Escenario 7: un negocio desactivado no es seleccionable en
 * /select-tenant, que rechaza el cambio con flash de error.
 */
final class AdminTenantShowTest extends FunctionalTestCase
{
    public function testTenantDetailShowsMetricsAndMembers(): void
    {
        $client = $this->seedAndLogin();

        ['tenant' => $ajeno, 'user' => $ajenoAdmin] = $this->seedForeignTenantData();

        $crawler = $client->request('GET', sprintf('/admin/tenants/%d', $ajeno->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Ajeno');
        $this->assertSelectorTextContains('h1', 'activo');

        $this->assertSame('1', $crawler->filter('#metrica-usuarios')->text());
        $this->assertSame('1', $crawler->filter('#metrica-inversiones')->text());
        $this->assertSame('1', $crawler->filter('#metrica-ventas')->text());
        $this->assertSame(
            $this->expectedSalesTotal($ajeno->getId()),
            $crawler->filter('#metrica-monto-vendido')->text(),
            'El monto vendido debe salir de la BD, no de la vista.'
        );

        $member = $crawler->filter(sprintf('#miembro-%d', $ajenoAdmin->getId()));
        $this->assertSame(1, $member->count(), 'El miembro del negocio debe listarse.');
        $this->assertStringContainsString('admin@ajeno.local', $member->text());
    }

    public function testTenantDetailMetricsAreIsolatedFromOtherTenants(): void
    {
        $client = $this->seedAndLogin();
        ['tenant' => $ajeno] = $this->seedForeignTenantData();

        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();

        $crawler = $client->request('GET', sprintf('/admin/tenants/%d', $demo->getId()));
        $this->assertResponseIsSuccessful();

        $this->assertSame(
            '0',
            $crawler->filter('#metrica-ventas')->text(),
            'Demo no tiene ventas: la venta y el monto de Ajeno no deben colarse.'
        );
        $this->assertSame('0,00', $crawler->filter('#metrica-monto-vendido')->text());
        $this->assertSame('1', $crawler->filter('#metrica-usuarios')->text());
    }

    public function testTenantToggleDeactivatesAndReactivates(): void
    {
        $client = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();

        $crawler = $client->request('GET', sprintf('/admin/tenants/%d', $demo->getId()));
        $form = $crawler->filter(sprintf('form[action="/admin/tenants/%d/toggle-active"]', $demo->getId()));
        $this->assertSame(1, $form->count(), 'La ficha debe ofrecer el botón de activar/desactivar.');

        $client->submit($form->form());
        $this->assertToggleRedirect($client, $demo->getId());
        $client->followRedirect();

        $this->assertFalse($this->isActive($demo->getId()), 'Tras el toggle el negocio debe quedar inactivo.');
        $this->assertSame('Activar negocio', $client->getCrawler()->filter('#btn-toggle-tenant')->text());
        $this->assertGreaterThan(
            0,
            $client->getCrawler()->filter('[role="status"]')->count(),
            'El toggle debe confirmar con un flash (tipo success).'
        );

        // Reactiva con el token de la página recién renderizada.
        $crawler = $client->request('GET', sprintf('/admin/tenants/%d', $demo->getId()));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/tenants/%d/toggle-active"]', $demo->getId()))->form()
        );
        $client->followRedirect();

        $this->assertTrue($this->isActive($demo->getId()), 'El segundo toggle debe reactivarlo.');
        $this->assertSame('Desactivar negocio', $client->getCrawler()->filter('#btn-toggle-tenant')->text());
    }

    public function testTenantToggleRequiresCsrfToken(): void
    {
        $client = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();

        $client->request('POST', sprintf('/admin/tenants/%d/toggle-active', $demo->getId()));

        $client->followRedirect();
        $this->assertTrue($this->isActive($demo->getId()), 'Sin CSRF no debe cambiar el estado.');
        $this->assertSelectorTextContains('[role="alert"]', 'Solicitud inválida.');
    }

    public function testDeactivatedTenantIsNotSelectable(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        $paused = new Tenant();
        $paused->setName('Pausado');
        $paused->setSlug('pausado');
        $paused->setIsActive(false);

        $membership = new TenantMembership($user, $paused);
        $membership->setRole(TenantMembership::ROLE_ADMIN);
        $user->addMembership($membership);

        $this->em->persist($paused);
        $this->em->persist($membership);
        $this->em->flush();

        $this->loginAs($client, 'admin@demo.local', 'demo');
        $this->assertLocationEndsWith($client, '/select-tenant');

        $crawler = $client->request('GET', '/select-tenant');
        $this->assertResponseIsSuccessful();

        $client->submit(
            $crawler->filter(sprintf('form[action="/select-tenant/%d"]', $membership->getId()))->form()
        );
        $this->assertLocationEndsWith($client, '/select-tenant');

        $client->followRedirect();
        $this->assertSelectorTextContains(
            '[role="alert"]',
            'deshabilitado',
            'Un negocio desactivado no debe poder elegirse.'
        );
    }

    /**
     * Kernel compartido primero (inicializa $em en la base), luego los
     * seeds y por último el login del super_admin.
     */
    private function seedAndLogin(): KernelBrowser
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        return $client;
    }

    /**
     * Negocio "Ajeno" con su admin, una inversión y una venta: el
     * segundo conjunto de datos con el que se comprueba el aislamiento.
     * (Demo no se siembra aquí: lo hace cada test cuando lo necesita.)
     *
     * @return array{tenant: Tenant, user: \App\Entity\AppUser}
     */
    private function seedForeignTenantData(): array
    {
        $sale = $this->seedForeignSale();

        $memberships = $this->em->getRepository(\App\Entity\TenantMembership::class)
            ->findBy(['tenant' => $sale->getTenant()]);

        return [
            'tenant' => $sale->getTenant(),
            'user' => $memberships[0]->getUser(),
        ];
    }

    /**
     * Monto no anulado del negocio, calculado con SQL crudo para no
     * repetir la consulta que acaba de implementar el repositorio.
     */
    private function expectedSalesTotal(int $tenantId): string
    {
        $total = (float) static::getContainer()->get('doctrine')->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(total_amount), 0) FROM sale WHERE tenant_id = :tid AND voided_at IS NULL',
            ['tid' => $tenantId]
        );

        return number_format($total, 2, ',', '.');
    }

    private function isActive(int $tenantId): bool
    {
        $this->em->clear();

        return (bool) static::getContainer()->get('doctrine')->getConnection()->fetchOne(
            'SELECT is_active FROM tenant WHERE id = :id',
            ['id' => $tenantId]
        );
    }

    private function assertToggleRedirect(KernelBrowser $client, int $tenantId): void
    {
        $location = (string) $client->getResponse()->headers->get('Location');

        $this->assertTrue($client->getResponse()->isRedirect(), 'El toggle debe redirigir a la ficha.');
        $this->assertStringEndsWith(sprintf('/admin/tenants/%d', $tenantId), $location);
    }

    private function assertLocationEndsWith(KernelBrowser $client, string $path): void
    {
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertTrue($client->getResponse()->isRedirect(), 'Se esperaba un redirect.');
        $this->assertStringEndsWith($path, $location);
    }
}
