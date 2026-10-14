<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Gestión de membresías desde /admin/users/{id} (Fase 6.2, escenarios
 * 3 a 8).
 *
 * Toda operación corre detrás de ROLE_SUPER_ADMIN (security.yaml) y de
 * CSRF plano por acción. Las invariantes se validan en el SERVIDOR
 * (MembershipManagementService): ni el duplicado, ni la última membresía
 * del negocio, ni la última del usuario, ni el último administrador se
 * resuelven confiando en el formulario.
 */
final class AdminMembershipManagementTest extends FunctionalTestCase
{
    public function testAddMembershipToExistingTenant(): void
    {
        [$client] = $this->seedAndLogin();
        ['tenant' => $demo, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        $ops = $this->seedTenant('Ops', 'ops');

        $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        $this->assertResponseIsSuccessful();

        $client->request(
            'POST',
            sprintf('/admin/users/%d/memberships', $user->getId()),
            [
                '_csrf_token' => $this->csrfFrom($client->getCrawler(), '#form-agregar-membresia'),
                'tenant' => $ops->getId(),
                'role' => TenantMembership::ROLE_USER,
            ]
        );

        $this->assertLocationEndsWith($client, sprintf('/admin/users/%d', $user->getId()));
        $client->followRedirect();

        $this->assertSame(1, $client->getCrawler()->filter(sprintf('#membresia-%d', $ops->getId()))->count());
        $this->assertSame(
            TenantMembership::ROLE_USER,
            $this->membershipRole($user->getId(), $ops->getId()),
            'La membresía debe quedar persistida con el rol elegido.'
        );
        $this->assertSame(
            TenantMembership::ROLE_ADMIN,
            $this->membershipRole($user->getId(), $demo->getId()),
            'La membresía existente no debe tocarse.'
        );
    }

    public function testAddDuplicateMembershipFails(): void
    {
        [$client] = $this->seedAndLogin();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $ops = $this->seedTenant('Ops', 'ops');

        $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        $token = $this->csrfFrom($client->getCrawler(), '#form-agregar-membresia');
        $post = fn (): Crawler => $client->request(
            'POST',
            sprintf('/admin/users/%d/memberships', $user->getId()),
            ['_csrf_token' => $token, 'tenant' => $ops->getId(), 'role' => TenantMembership::ROLE_USER]
        );

        $post();
        $client->followRedirect();

        // Misma petición otra vez: la unique (user, tenant) existe y el
        // servicio debe rechazarlo ANTES de tocar la BD.
        $post();
        $client->followRedirect();

        $this->assertSelectorTextContains('[role="alert"]', 'ya pertenece a este negocio');
        $this->assertSame(
            1,
            $this->countMemberships($user->getId(), $ops->getId()),
            'No debe crearse una segunda membresía duplicada.'
        );
    }

    public function testChangeMembershipRole(): void
    {
        [$client] = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();
        $ops = $this->seedTenant('Ops', 'ops');
        $this->seedMember($ops, 'alice@demo.local', TenantMembership::ROLE_ADMIN);
        $bob = $this->seedMember($ops, 'bob@demo.local', TenantMembership::ROLE_USER);
        $this->addMembership($bob, $demo, TenantMembership::ROLE_USER);

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $bob->getId()));
        $formCrawler = $crawler->filter(sprintf('form[action="/admin/memberships/%d/change-role"]', $this->membershipId($bob, $ops)));
        $this->assertSame(1, $formCrawler->count(), 'Cada membresía debe ofrecer cambiar el rol.');

        // El objeto Form se construye UNA vez: setValues muta esa
        // instancia, y un ->form() nuevo volvería al valor por defecto
        // del DOM.
        $form = $formCrawler->form();
        $form->setValues(['role' => TenantMembership::ROLE_ADMIN]);
        $client->submit($form);
        $this->assertLocationEndsWith($client, sprintf('/admin/users/%d', $bob->getId()));
        $client->followRedirect();

        $this->assertSame(
            TenantMembership::ROLE_ADMIN,
            $this->membershipRole($bob->getId(), $ops->getId()),
            'El rol de la membresía debe cambiar en la BD.'
        );
        $this->assertSelectorTextContains('[role="status"]', 'Rol actualizado');
    }

    public function testRemoveMembership(): void
    {
        [$client] = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();
        $ops = $this->seedTenant('Ops', 'ops');
        $this->seedMember($ops, 'alice@demo.local', TenantMembership::ROLE_ADMIN);
        $bob = $this->seedMember($ops, 'bob@demo.local', TenantMembership::ROLE_USER);
        $this->addMembership($bob, $demo, TenantMembership::ROLE_USER);

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $bob->getId()));
        $form = $crawler->filter(sprintf('form[action="/admin/memberships/%d/remove"]', $this->membershipId($bob, $ops)));
        $this->assertSame(1, $form->count(), 'Cada membresía debe poder quitarse.');

        $client->submit($form->form());
        $this->assertLocationEndsWith($client, sprintf('/admin/users/%d', $bob->getId()));
        $client->followRedirect();

        $this->assertSame(0, $this->countMemberships($bob->getId(), $ops->getId()), 'La membresía debe borrarse.');
        $this->assertSame(
            1,
            $client->getCrawler()->filter(sprintf('#membresia-%d', $demo->getId()))->count(),
            'La otra membresía del usuario se conserva.'
        );
        $this->assertSelectorTextContains('[role="status"]', 'quitada');
    }

    public function testCannotRemoveLastMembershipOfTenant(): void
    {
        [$client] = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();
        $ops = $this->seedTenant('Ops', 'ops');
        $alice = $this->seedMember($ops, 'alice@demo.local', TenantMembership::ROLE_ADMIN);
        $this->addMembership($alice, $demo, TenantMembership::ROLE_USER);

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $alice->getId()));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/memberships/%d/remove"]', $this->membershipId($alice, $ops)))->form()
        );
        $client->followRedirect();

        $this->assertSelectorTextContains('[role="alert"]', 'última membresía del negocio');
        $this->assertSame(
            1,
            $this->countMemberships($alice->getId(), $ops->getId()),
            'El negocio no puede quedar sin usuarios.'
        );
    }

    public function testCannotRemoveLastMembershipOfUser(): void
    {
        [$client] = $this->seedAndLogin();
        $ops = $this->seedTenant('Ops', 'ops');
        $this->seedMember($ops, 'alice@demo.local', TenantMembership::ROLE_ADMIN);
        $bob = $this->seedMember($ops, 'bob@demo.local', TenantMembership::ROLE_USER);

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $bob->getId()));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/memberships/%d/remove"]', $this->membershipId($bob, $ops)))->form()
        );
        $client->followRedirect();

        $this->assertSelectorTextContains('[role="alert"]', 'última membresía del usuario');
        $this->assertSame(
            1,
            $this->countMemberships($bob->getId(), $ops->getId()),
            'El usuario no puede quedarse sin acceso a ningún negocio.'
        );
    }

    public function testCannotDemoteTheOnlyAdminOfTenant(): void
    {
        [$client] = $this->seedAndLogin();
        $ops = $this->seedTenant('Ops', 'ops');
        $alice = $this->seedMember($ops, 'alice@demo.local', TenantMembership::ROLE_ADMIN);
        $this->seedMember($ops, 'bob@demo.local', TenantMembership::ROLE_USER);

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $alice->getId()));
        $form = $crawler->filter(sprintf('form[action="/admin/memberships/%d/change-role"]', $this->membershipId($alice, $ops)))->form();
        $form->setValues(['role' => TenantMembership::ROLE_USER]);
        $client->submit($form);
        $client->followRedirect();

        $this->assertSelectorTextContains('[role="alert"]', 'al menos un administrador');
        $this->assertSame(
            TenantMembership::ROLE_ADMIN,
            $this->membershipRole($alice->getId(), $ops->getId()),
            'El único administrador del negocio no debe poder degradarse.'
        );
    }

    /**
     * El rol GLOBAL no se gestiona en esta fase: ni el alta, ni el cambio
     * de rol de membresía, ni la baja tocan app_user.roles (ADR-0019).
     * El super_admin lo ejercita sobre su propia cuenta.
     */
    public function testSuperAdminKeepsGlobalRoleAfterMembershipChanges(): void
    {
        [$client, $superAdmin] = $this->seedAndLogin();
        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();
        $ops = $this->seedTenant('Ops', 'ops');
        $this->seedMember($ops, 'alice@demo.local', TenantMembership::ROLE_ADMIN);

        $this->addToMembership($client, $superAdmin, $demo, TenantMembership::ROLE_USER);
        $this->addToMembership($client, $superAdmin, $ops, TenantMembership::ROLE_USER);

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $superAdmin->getId()));
        $change = $crawler->filter(sprintf('form[action="/admin/memberships/%d/change-role"]', $this->membershipId($superAdmin, $ops)))->form();
        $change->setValues(['role' => TenantMembership::ROLE_ADMIN]);
        $client->submit($change);
        $client->followRedirect();

        $crawler = $client->request('GET', sprintf('/admin/users/%d', $superAdmin->getId()));
        $client->submit(
            $crawler->filter(sprintf('form[action="/admin/memberships/%d/remove"]', $this->membershipId($superAdmin, $ops)))->form()
        );
        $client->followRedirect();

        $this->assertSame(
            0,
            $this->countMemberships($superAdmin->getId(), $ops->getId()),
            'La membresía del tenant debe poder quitarse.'
        );

        $this->em->clear();
        /** @var AppUser $reloaded */
        $reloaded = $this->em->find(AppUser::class, $superAdmin->getId());
        $this->assertTrue($reloaded->isSuperAdmin(), 'El rol global no debe perderse.');
        $this->assertSame(
            [AppUser::ROLE_SUPER_ADMIN, 'ROLE_USER'],
            $reloaded->getRoles(),
            'Ninguna operación de membresías debe escribir app_user.roles.'
        );
    }

    /**
     * @return array{KernelBrowser, AppUser}
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

    private function seedMember(Tenant $tenant, string $email, string $role): AppUser
    {
        $user = new AppUser();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'secret1234'));

        $user->addMembership($this->newMembership($user, $tenant, $role));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function addMembership(AppUser $user, Tenant $tenant, string $role): TenantMembership
    {
        $membership = $this->newMembership($user, $tenant, $role);
        $user->addMembership($membership);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    private function newMembership(AppUser $user, Tenant $tenant, string $role): TenantMembership
    {
        $membership = new TenantMembership($user, $tenant);
        $membership->setRole($role);
        $this->em->persist($membership);

        return $membership;
    }

    /**
     * Ejecuta el POST de "agregar a un tenant" con el CSRF de la ficha.
     */
    private function addToMembership(KernelBrowser $client, AppUser $user, Tenant $tenant, string $role): void
    {
        $client->request('GET', sprintf('/admin/users/%d', $user->getId()));
        $this->assertResponseIsSuccessful();

        $client->request('POST', sprintf('/admin/users/%d/memberships', $user->getId()), [
            '_csrf_token' => $this->csrfFrom($client->getCrawler(), '#form-agregar-membresia'),
            'tenant' => $tenant->getId(),
            'role' => $role,
        ]);
        $client->followRedirect();
    }

    private function csrfFrom(Crawler $crawler, string $selector): string
    {
        $token = $crawler->filter($selector.' input[name="_csrf_token"]')->attr('value');
        $this->assertNotNull($token, sprintf('Falta el token CSRF en %s.', $selector));

        return (string) $token;
    }

    private function membershipId(AppUser $user, Tenant $tenant): int
    {
        $membership = $this->connection()->fetchOne(
            'SELECT id FROM tenant_membership WHERE user_id = :u AND tenant_id = :t',
            ['u' => $user->getId(), 't' => $tenant->getId()]
        );

        $this->assertNotSame(false, $membership, 'La membresía debe existir.');

        return (int) $membership;
    }

    private function membershipRole(int $userId, int $tenantId): ?string
    {
        $role = $this->connection()->fetchOne(
            'SELECT role FROM tenant_membership WHERE user_id = :u AND tenant_id = :t',
            ['u' => $userId, 't' => $tenantId]
        );

        return false === $role ? null : (string) $role;
    }

    private function countMemberships(int $userId, int $tenantId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM tenant_membership WHERE user_id = :u AND tenant_id = :t',
            ['u' => $userId, 't' => $tenantId]
        );
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
