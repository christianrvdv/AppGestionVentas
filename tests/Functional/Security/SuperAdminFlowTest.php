<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\AppUser;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Flujo global del super_admin (ADR-0016).
 *
 * Sin membresías ni tenant activo, el rol global debe poder:
 *   - loguearse y caer directo en el dashboard (ni /onboarding ni
 *     /select-tenant, que son caminos "de elegir negocio");
 *   - ver el dashboard con totales en cero (no hay negocio del cual
 *     consolidar);
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

        // Totales en cero con el mismo shape que con negocio (la vista
        // no rompe sin tenant) y sin tasa USD registrada.
        $this->assertSelectorTextContains('section:has(#titulos-totales)', 'Total invertido');
        $this->assertSelectorTextContains('section:has(#titulos-totales)', '0,00');
        $this->assertSelectorTextContains('section:has(#titulos-totales)', 'Sin registrar');
        $this->assertSelectorTextContains('#titulos-inversiones + *', 'No hay inversiones activas.');
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
     * prod) y SIN membresías: el caso que existía gracias a
     * app:user:create --super-admin.
     */
    private function seedSuperAdmin(): AppUser
    {
        $user = new AppUser();
        $user->setEmail('super@demo.local');
        $user->setRoles([AppUser::ROLE_SUPER_ADMIN]);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'super1234'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

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
