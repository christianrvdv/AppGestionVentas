<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Cambio de contraseña y el cambio FORZADO tras un reset (Fase 6.1,
 * opción A): reset ⇒ must_change_password = true ⇒ el próximo login
 * manda a /cambiar-password y EnforcePasswordChangeListener bloquea el
 * resto de la app hasta completarlo.
 */
final class AdminPasswordChangeTest extends FunctionalTestCase
{
    /**
     * Flujo completo: reset (super_admin) → login con la temporal ⇒
     * destino /cambiar-password; cualquier otra ruta rebotará hasta
     * completar el cambio.
     */
    public function testResetForcesChangeOnNextLoginAndBlocksRestOfApp(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $temporaryPassword = $this->resetPasswordAsSuperAdmin($client, $user->getId());

        // El login válido no va al dashboard: va al formulario de cambio.
        $client->restart();
        $this->loginAs($client, 'admin@demo.local', $temporaryPassword);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertTrue($client->getResponse()->isRedirect(), 'El login debe redirigir.');
        $this->assertStringEndsWith('/cambiar-password', $location);

        // El formulario está disponible (y no lo frena RequireTenant).
        $crawler = $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $crawler->filter('form[action="/cambiar-password"]')->count());

        // Mientras el flag siga activo, el resto de la app re-bota.
        $client->request('GET', '/');
        $this->assertTrue($client->getResponse()->isRedirect(), '/ debe redirigir al cambio.');
        $this->assertStringEndsWith(
            '/cambiar-password',
            (string) $client->getResponse()->headers->get('Location')
        );

        // ...pero las rutas de recuperación quedan libres (Fase 7):
        // /select-tenant no debe bloquear el flujo M2M.
        $client->request('GET', '/select-tenant');
        $this->assertResponseIsSuccessful();
    }

    /**
     * Completar el cambio libera el acceso: la vieja (la temporal) deja
     * de valer, la nueva autentica y ya no hay redirección forzada.
     */
    public function testForcedChangeUpdatesPasswordAndClearsFlag(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $temporaryPassword = $this->resetPasswordAsSuperAdmin($client, $user->getId());

        $client->restart();
        $this->loginAs($client, 'admin@demo.local', $temporaryPassword);
        $crawler = $client->followRedirect();

        // Contraseña actual errónea: el cambio no se aplica.
        $form = $crawler->selectButton('Guardar contraseña')->form();
        $form['password_change_form[currentPassword]'] = 'no-es-la-correcta';
        $form['password_change_form[newPassword]'] = 'nueva-clave-1';
        $form['password_change_form[confirmPassword]'] = 'nueva-clave-1';
        $client->submit($form);
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString(
            'La contraseña actual no es válida.',
            $client->getResponse()->getContent(),
            'El formulario debe marcar el error.'
        );
        $this->assertTrue($this->mustChange($user->getId()), 'El flag no debe tocarse si el actual falla.');
        $this->assertTrue(
            $this->passwordStillAccepts($user->getId(), $temporaryPassword),
            'La temporal sigue vigente.'
        );

        // Confirmación que no coincide: tampoco se aplica.
        $crawler = $client->getCrawler();
        $form = $crawler->selectButton('Guardar contraseña')->form();
        $form['password_change_form[currentPassword]'] = $temporaryPassword;
        $form['password_change_form[newPassword]'] = 'nueva-clave-1';
        $form['password_change_form[confirmPassword]'] = 'distinta';
        $client->submit($form);
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString(
            'no coinciden',
            $client->getResponse()->getContent(),
            'El formulario debe avisar que las contraseñas no coinciden.'
        );

        // Cambio correcto: flag bajo y fuera de la redirección forzada.
        $form = $client->getCrawler()->selectButton('Guardar contraseña')->form();
        $form['password_change_form[currentPassword]'] = $temporaryPassword;
        $form['password_change_form[newPassword]'] = 'nueva-clave-1';
        $form['password_change_form[confirmPassword]'] = 'nueva-clave-1';
        $client->submit($form);

        $this->assertTrue($client->getResponse()->isRedirect(), 'El cambio debe redirigir fuera del formulario.');
        $this->assertFalse($this->mustChange($user->getId()), 'El flag debe bajar al cambiar la contraseña.');

        // La temporal ya no sirve; la nueva entra sin forzar nada.
        $client->restart();
        $this->loginAs($client, 'admin@demo.local', $temporaryPassword);
        $this->assertStringContainsString(
            '/login',
            (string) $client->getResponse()->headers->get('Location'),
            'La contraseña vieja ya no debe autenticar.'
        );

        $client->restart();
        $this->loginAs($client, 'admin@demo.local', 'nueva-clave-1');
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertTrue($client->getResponse()->isRedirect(), 'La nueva contraseña debe autenticar.');
        $this->assertStringNotContainsString('/login', $location);
        $this->assertStringNotContainsString(
            '/cambiar-password',
            $location,
            'Sin el flag no debe volver a forzar el cambio.'
        );
    }

    /**
     * El formulario también es usable de forma voluntaria (flag en false).
     */
    public function testChangeIsAvailableWithoutFlag(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/cambiar-password');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Guardar contraseña')->form();
        $form['password_change_form[currentPassword]'] = 'demo';
        $form['password_change_form[newPassword]'] = 'clave-voluntaria';
        $form['password_change_form[confirmPassword]'] = 'clave-voluntaria';
        $client->submit($form);

        $this->assertTrue($client->getResponse()->isRedirect(), 'El cambio debe redirigir fuera del formulario.');

        $client->restart();
        $this->loginAs($client, 'admin@demo.local', 'clave-voluntaria');
        $this->assertStringNotContainsString(
            '/login',
            (string) $client->getResponse()->headers->get('Location'),
            'La nueva contraseña debe autenticar.'
        );
        $this->assertFalse($this->mustChange($user->getId()), 'La clave voluntaria no deja el flag activo.');
    }

    /**
     * CSRF: sin token el cambio no se aplica (el formulario re-renderiza
     * con error de validación).
     */
    public function testChangeRequiresCsrf(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $client->request('POST', '/cambiar-password', [
            'password_change' => [
                'currentPassword' => 'demo',
                'newPassword' => 'clave-nueva-x',
                'confirmPassword' => 'clave-nueva-x',
            ],
        ]);

        $this->assertFalse(
            $this->passwordStillAccepts($user->getId(), 'clave-nueva-x'),
            'Sin CSRF no debe cambiar la contraseña.'
        );
        $this->assertTrue(
            $this->passwordStillAccepts($user->getId(), 'demo'),
            'La contraseña original debe seguir intacta.'
        );
    }

    /**
     * Ejecuta el reset desde la ficha (como lo haría un super_admin)
     * y devuelve la contraseña temporal del flash.
     */
    private function resetPasswordAsSuperAdmin(KernelBrowser $client, int $userId): string
    {
        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $userId));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/users/%d/reset-password"]', $userId))->form()
        );
        $client->followRedirect();

        $flash = $client->getCrawler()->filter('[role="status"]')->text();
        $this->assertSame(
            1,
            preg_match('/Contraseña temporal para [^:]+: (\S+)\./u', $flash, $matches),
            'El flash debe incluir la contraseña temporal.'
        );

        return $matches[1];
    }

    /**
     * Comprueba si una contraseña sigue siendo la guardada en BD.
     * Limpia el Unit of Work antes de leer (AGENTS.md: los tests que
     * afirman sobre estado de BD deben clear()).
     */
    private function passwordStillAccepts(int $userId, string $password): bool
    {
        $this->em->clear();

        $hash = (string) static::getContainer()->get('doctrine')->getConnection()->fetchOne(
            'SELECT password_hash FROM app_user WHERE id = :id',
            ['id' => $userId]
        );

        return password_verify($password, $hash);
    }

    private function mustChange(int $userId): bool
    {
        $this->em->clear();

        return (bool) static::getContainer()->get('doctrine')->getConnection()->fetchOne(
            'SELECT must_change_password FROM app_user WHERE id = :id',
            ['id' => $userId]
        );
    }
}
