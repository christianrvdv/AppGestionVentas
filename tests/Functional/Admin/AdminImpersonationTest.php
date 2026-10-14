<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Impersonation de tenant (Fase 6.2, escenarios 1 y 2).
 *
 * El impersonation es DE TENANT, no de usuario (ADR-0019): la marca vive
 * en la sesión, el super_admin conserva su identidad y su rol, y lo único
 * que cambia es el negocio activo — con el TenantFilter RE-HABILITADO, que
 * es lo que hace que vea solo datos de ese negocio.
 *
 * Escenario 1: entra → banner → solo datos del tenant → el panel /admin
 * sigue accesible (sigue siendo super_admin) → sale → vuelve al panel.
 * Escenario 2: cada entrada y salida deja una fila en impersonation_log
 * (started_at al entrar, ended_at al salir).
 */
final class AdminImpersonationTest extends FunctionalTestCase
{
    public function testSuperAdminEntersAsTenantAndStops(): void
    {
        [$client] = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();
        $ajeno = $this->seedTenant('Ajeno', 'ajeno');
        $this->seedProduct($demo, 'Producto Demo');
        $this->seedProduct($ajeno, 'Producto Ajeno');

        $crawler = $client->request('GET', sprintf('/admin/tenants/%d', $demo->getId()));
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[action="/admin/tenants/%d/impersonate"]', $demo->getId()));
        $this->assertSame(1, $form->count(), 'La ficha del negocio debe ofrecer entrar como él.');

        $client->submit($form->form());
        $this->assertLocationEndsWith($client, '/');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $banner = $client->getCrawler()->filter('#banner-impersonation');
        $this->assertSame(1, $banner->count(), 'El banner debe verse en TODAS las páginas.');
        $this->assertStringContainsString('Viendo como Demo', $banner->text());

        // Sigue siendo super_admin: el panel global no se cierra.
        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $client->getCrawler()->filter('#banner-impersonation')->count());

        // ...pero los datos de negocio son los del tenant impersonado.
        $client->request('GET', '/productos');
        $this->assertResponseIsSuccessful();
        $page = $client->getCrawler()->text();
        $this->assertStringContainsString('Producto Demo', $page, 'Debe ver los productos del tenant elegido.');
        $this->assertStringNotContainsString('Producto Ajeno', $page, 'No debe ver datos de otro negocio.');

        // Sale: limpia la marca y vuelve al panel de administración.
        $stop = $client->getCrawler()->filter('form#form-impersonate-stop');
        $this->assertSame(1, $stop->count(), 'El banner debe ofrecer la salida.');

        $client->submit($stop->form());
        $this->assertLocationEndsWith($client, '/admin');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            0,
            $client->getCrawler()->filter('#banner-impersonation')->count(),
            'Al salir el banner debe desaparecer.'
        );
    }

    public function testImpersonationIsAudited(): void
    {
        [$client, $superAdmin] = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();

        $crawler = $client->request('GET', sprintf('/admin/tenants/%d', $demo->getId()));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/tenants/%d/impersonate"]', $demo->getId()))->form()
        );
        $client->followRedirect();

        $row = $this->lastAuditRow();
        $this->assertNotNull($row, 'Entrar como tenant debe registrar una fila de auditoría.');
        $this->assertSame($superAdmin->getId(), (int) $row['super_admin_id'], 'Debe registrar QUIÉN entra.');
        $this->assertSame($demo->getId(), (int) $row['tenant_id'], 'Debe registrar A QUÉ tenant.');
        $this->assertNotEmpty($row['started_at'], 'Debe registrar CUÁNDO empieza.');
        $this->assertNull($row['ended_at'], 'Mientras esté activa la sesión queda abierta.');

        $client->submit($client->getCrawler()->filter('form#form-impersonate-stop')->form());
        $client->followRedirect();

        $row = $this->lastAuditRow();
        $this->assertNotNull($row, 'La fila debe seguir existiendo al salir.');
        $this->assertNotNull($row['ended_at'], 'Al salir debe quedar registrado CUÁNDO termina.');
    }

    public function testImpersonationRequiresCsrf(): void
    {
        [$client] = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();

        $client->request('POST', sprintf('/admin/tenants/%d/impersonate', $demo->getId()));
        $client->followRedirect();

        $this->assertSame(0, $this->auditCount(), 'Sin CSRF no debe empezar el impersonation.');
        $this->assertSame(1, $client->getCrawler()->filter('[role="alert"]')->count());
    }

    /**
     * Kernel compartido primero (inicializa $em en la base), la cuenta de
     * super_admin y por último la sesión.
     *
     * @return array{KernelBrowser, \App\Entity\AppUser}
     */
    private function seedAndLogin(): array
    {
        $client = $this->createSharedKernelClient();
        $superAdmin = $this->seedSuperAdmin();
        $client->restart();
        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        return [$client, $superAdmin];
    }

    private function seedTenant(string $name, string $slug): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($name);
        $tenant->setSlug($slug);

        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /**
     * Última fila de auditoría vía SQL crudo: la lectura no debe depender
     * del TenantFilter, que durante un impersonation está habilitado.
     *
     * @return array<string, mixed>|null
     */
    private function lastAuditRow(): ?array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT super_admin_id, tenant_id, started_at, ended_at FROM impersonation_log ORDER BY id DESC LIMIT 1'
        );

        return $rows[0] ?? null;
    }

    private function auditCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM impersonation_log');
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    private function assertLocationEndsWith(KernelBrowser $client, string $path): void
    {
        $location = (string) $client->getResponse()->headers->get('Location');

        $this->assertTrue($client->getResponse()->isRedirect(), 'Se esperaba un redirect.');
        $this->assertStringEndsWith($path, $location);
    }
}
