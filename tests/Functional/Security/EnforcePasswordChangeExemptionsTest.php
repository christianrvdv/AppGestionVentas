<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\AppUser;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Exenciones del EnforcePasswordChangeListener (Fase 7).
 *
 * El reset forzado de Fase 6.1 bloquea el resto de la app mientras
 * `must_change_password` esté activo, pero DEBE dejar libres las rutas
 * desde las cuales el usuario se recupera o decide a qué negocio usar:
 * el destino mismo (/cambiar-password), salir (/logout), volver a entrar
 * (/login), elegir negocio (/select-tenant, crítico en flujo M2M con
 * varias membresías), crear el primer negocio (/onboarding), el panel
 * global (/admin, super_admin sin tenant) y los estáticos del framework
 * (/assets, /build), cuyo redirect rompería la página entera.
 */
final class EnforcePasswordChangeExemptionsTest extends FunctionalTestCase
{
    /**
     * Flujo M2M: el usuario con el flag activo llega a /select-tenant
     * (p. ej. con la pestaña de cambio abierta en otro tab) y debe poder
     * elegir negocio sin que lo rebote al formulario.
     */
    public function testSelectTenantIsExemptWhilePasswordChangeIsPending(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->enableMustChange($user);

        $this->loginAs($client, 'admin@demo.local', 'demo');
        $this->assertRedirectsToPasswordChange($client);

        $client->request('GET', '/select-tenant');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Tras cerrar sesión con el flag activo, /login debe renderizar el
     * formulario (no mandar de vuelta al cambio de contraseña).
     */
    public function testLoginAndLogoutAreExemptWhilePasswordChangeIsPending(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->enableMustChange($user);

        $this->loginAs($client, 'admin@demo.local', 'demo');
        $this->assertRedirectsToPasswordChange($client);

        $client->request('POST', '/logout', [
            '_csrf_token' => (string) static::getContainer()
                ->get(CsrfTokenManagerInterface::class)
                ->getToken('logout'),
        ]);
        $this->assertTrue($client->getResponse()->isRedirect(), '/logout debe poder usarse con el flag activo.');

        $client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
    }

    /**
     * super_admin sin tenant (ADR-0016): el panel /admin es su única vía
     * de trabajo y no depende de un negocio activo; el flag lo deja usar
     * mientras cambia la contraseña.
     */
    public function testAdminPanelIsExemptForSuperAdminWithPendingPasswordChange(): void
    {
        $client = $this->createSharedKernelClient();
        $superAdmin = $this->seedSuperAdmin();
        $this->seedDemoTenantAndAdmin();
        $this->enableMustChange($superAdmin);

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $this->assertRedirectsToPasswordChange($client);

        $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
    }

    /**
     * El formulario sigue siendo el destino del bloqueo, y cualquier otra
     * ruta sigue rebotando hasta completarlo: las exenciones no ablandan
     * el reset forzado.
     */
    public function testPasswordChangeFormIsTheDestinationAndOtherRoutesStillBounce(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->enableMustChange($user);

        $this->loginAs($client, 'admin@demo.local', 'demo');
        $this->assertRedirectsToPasswordChange($client);

        $crawler = $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $crawler->filter('form[action="/cambiar-password"]')->count());

        $client->request('GET', '/');
        $this->assertRedirectsToPasswordChange($client);
    }

    private function enableMustChange(AppUser $user): void
    {
        $user->setMustChangePassword(true);
        $this->em->flush();
    }

    private function assertRedirectsToPasswordChange(KernelBrowser $client): void
    {
        $this->assertTrue($client->getResponse()->isRedirect(), 'Debe redirigir al cambio forzado.');
        $this->assertStringEndsWith(
            '/cambiar-password',
            (string) $client->getResponse()->headers->get('Location')
        );
    }
}
