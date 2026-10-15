<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Flujo global del super_admin (ADR-0016).
 *
 * Sin membresías ni tenant activo, el rol global debe poder:
 *   - loguearse y caer directo en el dashboard (ni /onboarding ni
 *     /select-tenant, que son caminos "de elegir negocio");
 *   - ver el dashboard que explica el estado vacío con un alert
 *     (Fase 2: sin negocio no hay totales que consolidar, se evita
 *     pintar cifras en cero que sugieran un negocio real);
 *   - abrir /admin, la única ruta blanqueada sin tenant.
 *
 * El espejo del contrato: una cuenta normal (ROLE_ADMIN de membresía,
 * el rol más alto de negocio) NO entra a /admin: allí solo vale el rol
 * GLOBAL.
 */
final class SuperAdminFlowTest extends FunctionalTestCase
{
    public function testLoginGoesStraightToDashboardWithoutChoosingABusiness(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $this->assertRedirectPath(
            $client,
            '/',
            'El super_admin no tiene negocios que elegir: el login va al destino normal.'
        );

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Dashboard');
    }

    public function testDashboardRendersZeroTotalsWithoutActiveBusiness(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Fase 2: sin negocio activo la vista explica el estado vacío
        // (ADR-0016) en vez de pintar totales en cero; el header con la
        // tasa sin registrar se conserva y la vista no rompe sin tenant.
        $crawler = $client->getCrawler();
        $this->assertSelectorTextContains('h1', 'Dashboard');
        $this->assertSelectorTextContains('[role="alert"]', 'Sin negocio activo');
        $this->assertSelectorTextContains('[role="alert"]', 'no hay datos que consolidar');
        $this->assertSelectorTextContains('.badge-warning', 'Tasa USD sin registrar');
        $this->assertSame(
            0,
            $crawler->filter('#titulos-totales')->count(),
            'Sin negocio activo no se pinta la sección de totales en cero.'
        );
        $this->assertSame(
            0,
            $crawler->filter('#titulos-inversiones')->count(),
            'Sin negocio activo no se pinta la sección de inversiones.'
        );
    }

    public function testSuperAdminCanOpenAdminPanelWithoutActiveBusiness(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Administración');
    }

    public function testAdminPanelRejectsRegularMemberEvenWithHighestBusinessRole(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin(); // ROLE_ADMIN de membresía en Demo

        $this->loginAsDemoAdmin($client);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(
            403,
            'ROLE_ADMIN es un rol de MEMBRESÍA: el panel global exige ROLE_SUPER_ADMIN.'
        );
    }

    public function testAdminPanelRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();

        $client->request('GET', '/admin');
        $this->assertRedirectPath($client, '/login');
    }

    /**
     * super_admin real (hash con el hasher de la aplicación, como en
     * prod) y SIN membresías: lo crea el helper de la base
     * FunctionalTestCase::seedSuperAdmin().
     */
    private function assertRedirectPath(KernelBrowser $client, string $path, string $message = ''): void
    {
        $location = (string) $client->getResponse()->headers->get('Location');

        $this->assertTrue($client->getResponse()->isRedirect(), 'Se esperaba un redirect. '.$message);
        $this->assertStringEndsWith(
            $path,
            $location,
            sprintf('%s Location "%s" debe terminar en "%s".', $message, $location, $path)
        );
    }
}
