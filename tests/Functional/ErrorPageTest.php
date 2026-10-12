<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Páginas de error (Fase 7).
 *
 * - 404: cualquier ruta inexistente responde 404 (no un redirect de
 *   access_control, que solo aplica a rutas registradas).
 * - 403 custom: templates/bundles/TwigBundle/Exception/error403.html.twig
 *   reemplaza al "Forbidden" en inglés del default. Se prueba con un
 *   kernel SIN debug, porque en debug Symfony muestra el exception page
 *   con stack trace en vez del template (comportamiento de prod, que es
 *   el que importa).
 */
final class ErrorPageTest extends FunctionalTestCase
{
    public function testUnknownRouteRenders404(): void
    {
        $client = $this->createSharedKernelClient(['debug' => false]);
        $this->seedSuperAdmin();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $client->request('GET', '/ruta-inexistente');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testForbiddenRendersCustom403Page(): void
    {
        $client = $this->createSharedKernelClient(['debug' => false]);
        $this->seedSuperAdmin();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        // ROLE_ADMIN no es ROLE_SUPER_ADMIN: ^/admin responde 403
        // (mismo camino que un CSRF inválido, Fase 3b).
        $client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(403);

        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Acceso denegado', $content);
        $this->assertStringContainsString('Si tu sesión expiró', $content);
        $this->assertStringContainsString(
            'Volver al dashboard',
            $content,
            'La vista custom debe ofrecer la salida al dashboard.'
        );
    }
}
