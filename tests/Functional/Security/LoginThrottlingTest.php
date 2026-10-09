<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\FunctionalTestCase;

/**
 * Contrato de LoginThrottlingResponseListener: el 429 SOLO corresponde a
 * TooManyLoginAttemptsAuthenticationException. Un password incorrecto
 * dentro de la ventana de throttling es un fallo de autenticación
 * normal y debe seguir el failure_path (/login + mensaje de error).
 */
final class LoginThrottlingTest extends FunctionalTestCase
{
    public function testWrongPasswordWithinLimitRedirectsWithErrorAndNot429(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        static::getContainer()->get('cache.rate_limiter')->clear();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Iniciar sesión')->form();
        $client->submit($form, [
            'email' => 'admin@demo.local',
            'password' => 'wrong-password',
        ]);

        // Falla normal: el failure_path redirige a /login, nunca 429.
        $this->assertResponseRedirects('/login');

        $crawler = $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame(
            '[role="alert"]',
            'No se pudo iniciar sesión con los datos proporcionados.'
        );
        $this->assertStringNotContainsString('Demasiados intentos', (string) $crawler->html());

        // Sigue sin sesión: el dashboard exige autenticación.
        $client->request('GET', '/');
        $this->assertResponseRedirects('/login');

        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testSixthAttemptResponds429EvenWithValidCredentials(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        // Aíslate de cualquier estado previo del limiter (pool en memoria
        // por test, pero se limpia por si acaso y entre intents).
        static::getContainer()->get('cache.rate_limiter')->clear();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $crawler = $client->request('GET', '/login');
            $form = $crawler->selectButton('Iniciar sesión')->form();
            $client->submit($form, [
                'email' => 'admin@demo.local',
                'password' => 'wrong-password-'.$attempt,
            ]);

            $this->assertResponseRedirects('/login');
        }

        // 6.º intento con credenciales VÁLIDAS: el límite ya se agotó.
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Iniciar sesión')->form();
        $client->submit($form, [
            'email' => 'admin@demo.local',
            'password' => 'demo',
        ]);

        // El bloqueo responde 429 con mensaje genérico.
        $this->assertResponseStatusCodeSame(429);

        $body = $client->getResponse()->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('Demasiados intentos fallidos', $body);

        // No filtra información: ni la excepción cruda ni el email.
        $this->assertStringNotContainsString('TooManyLoginAttempts', $body);
        $this->assertStringNotContainsString('admin@demo.local', $body);

        // No quedó autenticado: / sigue exigiendo sesión.
        $client->request('GET', '/');
        $this->assertResponseRedirects('/login');

        static::getContainer()->get('cache.rate_limiter')->clear();
    }
}
