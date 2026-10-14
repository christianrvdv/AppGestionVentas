<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Autorización del panel global (Fase 6.1, alcance 6/7).
 *
 * security.yaml blinda ^/admin con ROLE_SUPER_ADMIN (rol GLOBAL, no de
 * membresía), así que una cuenta normal recibe 403 en TODAS las rutas
 * del panel — incluidos los POST de toggle/reset, que se rechazan por
 * ruta antes de llegar al controller ni al CSRF — y un anónimo va a
 * /login.
 */
final class AdminAccessTest extends FunctionalTestCase
{
    public function testEveryAdminRouteRejectsRegularMember(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user, 'membership' => $membership] = $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        foreach ($this->adminRoutes($tenant->getId(), $user->getId(), $membership->getId()) as [$method, $path]) {
            $client->request($method, $path);

            $this->assertResponseStatusCodeSame(
                403,
                sprintf('%s %s exige ROLE_SUPER_ADMIN.', $method, $path)
            );
        }
    }

    public function testEveryAdminRouteRequiresAuthentication(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $tenant, 'user' => $user, 'membership' => $membership] = $this->seedDemoTenantAndAdmin();

        foreach ($this->adminRoutes($tenant->getId(), $user->getId(), $membership->getId()) as [$method, $path]) {
            $client->request($method, $path);

            $location = (string) $client->getResponse()->headers->get('Location');
            $this->assertTrue(
                $client->getResponse()->isRedirect(),
                sprintf('%s %s sin sesión debe redirigir.', $method, $path)
            );
            $this->assertStringEndsWith(
                '/login',
                $location,
                sprintf('%s %s debe terminar en /login.', $method, $path)
            );
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private function adminRoutes(int $tenantId, int $userId, int $membershipId): array
    {
        return [
            ['GET', '/admin'],
            ['GET', '/admin/tenants'],
            ['GET', sprintf('/admin/tenants/%d', $tenantId)],
            ['GET', '/admin/users'],
            ['GET', sprintf('/admin/users/%d', $userId)],
            ['POST', sprintf('/admin/tenants/%d/toggle-active', $tenantId)],
            ['POST', sprintf('/admin/users/%d/toggle-active', $userId)],
            ['POST', sprintf('/admin/users/%d/reset-password', $userId)],
            // Fase 6.2: impersonation de tenant y gestión de membresías.
            ['POST', sprintf('/admin/tenants/%d/impersonate', $tenantId)],
            ['POST', '/admin/impersonate/stop'],
            ['POST', sprintf('/admin/users/%d/memberships', $userId)],
            ['POST', sprintf('/admin/memberships/%d/change-role', $membershipId)],
            ['POST', sprintf('/admin/memberships/%d/remove', $membershipId)],
        ];
    }
}
