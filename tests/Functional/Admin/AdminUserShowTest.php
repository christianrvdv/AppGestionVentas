<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Ficha, activación/desactivación y reset de contraseña de un usuario
 * (Fase 6.1, escenarios 5 y 6, más el reset de la matriz).
 *
 * Escenario 6: una cuenta desactivada no puede autenticarse
 * (AppUserChecker rechaza antes de arrancar la sesión) y se recupera
 * activándola de nuevo.
 *
 * Reset (decisión del panel, opción A): genera una contraseña temporal
 * que se muestra UNA vez en el flash y cambia el hash. El forzado del
 * cambio en el próximo login llega con la migración
 * must_change_password (Fase 6.1b); por eso el test solo exige que la
 * temporal AUTENTIQUE, sin importar a dónde mande el redirect.
 */
final class AdminUserShowTest extends FunctionalTestCase
{
    public function testUserDetailShowsProfileAndMemberships(): void
    {
        $client = $this->seedAndLogin();
        ['tenant' => $demo, 'user' => $user] = $this->seedDemoTenantAndAdmin();

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'admin@demo.local');

        $this->assertSame('Activa', $crawler->filter('#perfil-estado')->text());
        $this->assertStringContainsString('ROLE_ADMIN', $crawler->filter('#perfil-roles')->text());

        $membership = $crawler->filter(sprintf('#membresia-%d', $demo->getId()));
        $this->assertSame(1, $membership->count(), 'La membresía del negocio debe listarse.');
        $this->assertStringContainsString('Demo', $membership->text());
        $this->assertGreaterThan(
            0,
            $crawler->filter(sprintf('a[href="/admin/tenants/%d"]', $demo->getId()))->count(),
            'La membresía debe enlazar a la ficha del negocio.'
        );
    }

    public function testUserToggleDeactivatesAndBlocksLogin(): void
    {
        $client = $this->seedAndLogin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/users/%d/toggle-active"]', $user->getId()))->form()
        );
        $this->assertToggleRedirect($client, $user->getId());
        $client->followRedirect();

        $this->assertFalse($this->isActive($user->getId()), 'La cuenta debe quedar desactivada.');
        $this->assertSame('Activar usuario', $client->getCrawler()->filter('#btn-toggle-usuario')->text());
        $this->assertGreaterThan(0, $client->getCrawler()->filter('[role="status"]')->count());

        // Escenario 6: la cuenta desactivada no puede autenticarse.
        // restart() cierra la sesión del super_admin: con ella abierta,
        // /login redirigiría al dashboard en vez de mostrar el formulario.
        $client->restart();
        $this->loginAs($client, 'admin@demo.local', 'demo');
        $this->assertLoginRejected($client);

        // Reactivarla devuelve el acceso (volvemos como super_admin:
        // el intento anterior cerró la sesión).
        $this->loginAsSuperAdmin($client);
        $crawler = $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/users/%d/toggle-active"]', $user->getId()))->form()
        );
        $client->followRedirect();

        $this->assertTrue($this->isActive($user->getId()), 'El segundo toggle debe reactivarla.');
        $this->assertSame('Desactivar usuario', $client->getCrawler()->filter('#btn-toggle-usuario')->text());

        $client->restart();
        $this->loginAs($client, 'admin@demo.local', 'demo');
        $this->assertLoginAccepted($client);
    }

    public function testUserToggleRequiresCsrf(): void
    {
        $client = $this->seedAndLogin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        $client->request('POST', sprintf('/admin/users/%d/toggle-active', $user->getId()));
        $client->followRedirect();

        $this->assertTrue($this->isActive($user->getId()), 'Sin CSRF no debe cambiar el estado.');
        $this->assertSelectorTextContains('[role="alert"]', 'Solicitud inválida.');
    }

    public function testResetPasswordIssuesTemporaryPasswordAndInvalidatesOld(): void
    {
        $client = $this->seedAndLogin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        $form = $crawler->filter(sprintf('form[action="/admin/users/%d/reset-password"]', $user->getId()));
        $this->assertSame(1, $form->count(), 'La ficha debe ofrecer el reset de contraseña.');

        $client->submit($form->form());
        $this->assertToggleRedirect($client, $user->getId());
        $client->followRedirect();

        $flash = $client->getCrawler()->filter('[role="status"]')->text();
        $this->assertStringContainsString('Contraseña temporal', $flash);

        $this->assertSame(
            1,
            preg_match('/Contraseña temporal para [^:]+: (\S+)\./u', $flash, $matches),
            'El flash debe incluir la contraseña temporal extraíble.'
        );
        $temporaryPassword = $matches[1];
        $this->assertGreaterThanOrEqual(8, strlen($temporaryPassword));

        // La contraseña anterior queda invalidada.
        $client->restart();
        $this->loginAs($client, 'admin@demo.local', 'demo');
        $this->assertLoginRejected($client);

        // La temporal autentica (hacia donde sea que mande el redirect:
        // hoy al dashboard, con la migración a /cambiar-password).
        $client->restart();
        $this->loginAs($client, 'admin@demo.local', $temporaryPassword);
        $this->assertLoginAccepted($client);
    }

    public function testResetPasswordRequiresCsrf(): void
    {
        $client = $this->seedAndLogin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();

        $client->request('POST', sprintf('/admin/users/%d/reset-password', $user->getId()));
        $client->followRedirect();

        $this->assertSelectorTextContains('[role="alert"]', 'Solicitud inválida.');
    }

    private function seedAndLogin(): KernelBrowser
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        $this->loginAsSuperAdmin($client);

        return $client;
    }

    /**
     * Abre sesión como super_admin. restart() antes: si el cliente ya
     * tenía otra sesión (o ninguna), /login tiene que mostrar el
     * formulario, no redirigir al dashboard.
     */
    private function loginAsSuperAdmin(KernelBrowser $client): void
    {
        $client->restart();
        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();
    }

    private function assertToggleRedirect(KernelBrowser $client, int $userId): void
    {
        $location = (string) $client->getResponse()->headers->get('Location');

        $this->assertTrue($client->getResponse()->isRedirect(), 'La acción debe redirigir a la ficha.');
        $this->assertStringEndsWith(sprintf('/admin/users/%d', $userId), $location);
    }

    /**
     * Un intento de login debe terminar en el formulario con error, no
     * en una sesión abierta.
     */
    private function assertLoginRejected(KernelBrowser $client): void
    {
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertTrue(
            $client->getResponse()->isRedirect(),
            'Un login inválido debe volver al formulario.'
        );
        $this->assertStringContainsString('/login', $location);

        $client->followRedirect();
        $this->assertSame('/login', $client->getRequest()->getPathInfo());
        $this->assertGreaterThan(
            0,
            $client->getCrawler()->filter('[role="alert"]')->count(),
            'El formulario debe mostrar el error de autenticación.'
        );
    }

    /**
     * Un login válido redirige fuera del formulario de acceso (al
     * dashboard o a donde mande el listener de cambio de contraseña).
     */
    private function assertLoginAccepted(KernelBrowser $client): void
    {
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertTrue($client->getResponse()->isRedirect(), 'Un login válido debe redirigir.');
        $this->assertStringNotContainsString(
            '/login',
            $location,
            'Un login válido no debe volver al formulario.'
        );
    }

    private function isActive(int $userId): bool
    {
        $this->em->clear();

        return (bool) static::getContainer()->get('doctrine')->getConnection()->fetchOne(
            'SELECT is_active FROM app_user WHERE id = :id',
            ['id' => $userId]
        );
    }
}
