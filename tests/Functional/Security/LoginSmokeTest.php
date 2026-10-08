<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\FunctionalTestCase;

/**
 * Smoke tests de autenticación (Fase 1 UI).
 *
 * Cubren el DoD: GET /login 200, POST /login válido redirige a /,
 * GET / anónimo redirige a /login, y un negativo (credenciales
 * inválidas rebotan en /login con mensaje genérico).
 */
final class LoginSmokeTest extends FunctionalTestCase
{
    public function testLoginPageIsPublicAndRenders(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/login');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Iniciar sesión', $response->getContent());
    }

    public function testLoginWithValidCredentialsRedirectsToHome(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);

        $response = $client->getResponse();
        $this->assertTrue($response->isRedirect(), 'El login exitoso debe redirigir.');
        $this->assertSame('http://localhost/', $response->headers->get('Location'));
    }

    public function testRootRedirectsToLoginWhenAnonymous(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $client->request('GET', '/');

        $response = $client->getResponse();
        $this->assertTrue($response->isRedirect(), 'La raíz anónima debe redirigir al login.');
        $this->assertSame('http://localhost/login', $response->headers->get('Location'));
    }

    public function testLoginWithInvalidCredentialsStaysOnLoginWithGenericError(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Iniciar sesión')->form();

        $client->submit($form, [
            'tenant_slug' => 'demo',
            'email' => 'admin@demo.local',
            'password' => 'clave-mala',
        ]);

        $response = $client->getResponse();
        $this->assertTrue($response->isRedirect(), 'El login fallido debe rebotar en /login.');
        $this->assertSame('http://localhost/login', $response->headers->get('Location'));

        $client->followRedirect();
        $this->assertStringContainsString(
            'No se pudo iniciar sesión con los datos proporcionados.',
            $client->getResponse()->getContent()
        );
    }
}
