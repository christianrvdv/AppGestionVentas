<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Repository\Contract\TenantRepositoryInterface;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Flujo de negocio activo post-login (ADR-0015, Fase 3.5).
 *
 * El login es global: con una membresía se activa sola, con varias hay
 * que elegir en /select-tenant, con ninguna hay que crear el negocio en
 * /onboarding. Mientras no haya negocio activo en la sesión,
 * RequireTenantListener devuelve al selector en lugar de dejar pasar una
 * request que leería datos vacíos.
 */
final class TenantSelectionTest extends FunctionalTestCase
{
    public function testLoginWithSingleMembershipActivatesItAndOpensDashboard(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $this->assertRedirectPath($client, '/');

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        // El nav pinta el negocio activo (ActiveTenantProvider).
        $this->assertSelectorTextContains('header nav', 'Demo');
    }

    public function testLoginWithMultipleMembershipsRedirectsToSelectTenant(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->addMembership($user, 'segundo');

        $this->loginAsDemoAdmin($client);

        $this->assertRedirectPath(
            $client,
            '/select-tenant',
            'Con más de una membresía, el login debe mandar al selector.'
        );
    }

    public function testLoginWithoutMembershipsRedirectsToOnboarding(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedUserWithoutMemberships();

        $this->loginAs($client, 'sin-negocio@demo.local', 'demo');

        $this->assertRedirectPath(
            $client,
            '/onboarding',
            'Sin membresías no hay negocio que activar: toca el alta.'
        );
    }

    public function testProtectedRouteBouncesToSelectTenantWhenNoBusinessIsActive(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $this->addMembership($user, 'segundo');

        $this->loginAsDemoAdmin($client); // queda en /select-tenant, sin negocio activo
        $client->request('GET', '/');

        $this->assertRedirectPath(
            $client,
            '/select-tenant',
            'Sin negocio activo no se debe llegar al dashboard.'
        );
    }

    public function testSelectTenantSwitchesTheActiveBusiness(): void
    {
        $client = $this->createSharedKernelClient();
        ['tenant' => $demo, 'user' => $user] = $this->seedDemoTenantAndAdmin();
        $second = $this->addMembership($user, 'segundo', 'Segundo');
        $this->seedInvestmentFor($demo, $user, 'INV-DEMO-1');
        $this->seedInvestmentFor($second->getTenant(), $user, 'INV-OTRO-1');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/select-tenant');
        $this->assertResponseIsSuccessful();

        // Elige el segundo negocio (su formulario apunta a su membership).
        $client->submit(
            $crawler->filter(sprintf('form[action="/select-tenant/%d"]', $second->getId()))->form()
        );
        $this->assertRedirectPath($client, '/');

        $client->followRedirect();
        $body = $client->request('GET', '/')->filter('body')->text();

        $this->assertStringContainsString('INV-OTRO-1', $body);
        $this->assertStringNotContainsString(
            'INV-DEMO-1',
            $body,
            'El filtro de tenant debe seguir aislando por el negocio elegido.'
        );
        $this->assertSelectorTextContains('header nav', 'Segundo');
    }

    public function testSelectTenantRejectsMembershipOfAnotherUser(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $demoAdmin] = $this->seedDemoTenantAndAdmin();
        $this->addMembership($demoAdmin, 'propio', 'Propio'); // para que el selector renderice un form con token

        $foreign = $this->seedUserWithoutMemberships('ajeno@demo.local');
        $foreignMembership = $this->addMembership($foreign, 'ajeno', 'Ajeno');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/select-tenant');
        $this->assertResponseIsSuccessful();

        // El token CSRF es de sesión (id select_tenant), no de membership:
        // sirve para un id ajeno también, y justamente por eso la
        // validación real es la membership↔usuario en la query.
        $token = $crawler->filter('form[action^="/select-tenant"] input[name="_csrf_token"]')->attr('value');
        $this->assertNotNull($token, 'El selector debe renderizar un formulario con token CSRF.');

        $client->request('POST', sprintf('/select-tenant/%d', $foreignMembership->getId()), [
            '_csrf_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(404, 'Una membresía ajena no debe resolverse.');
    }

    public function testSelectTenantRequiresCsrfToken(): void
    {
        $client = $this->createSharedKernelClient();
        ['membership' => $membership] = $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client); // una sola membresía: ya activa, pero el POST sigue validando
        $client->request('POST', sprintf('/select-tenant/%d', $membership->getId()));

        $this->assertRedirectPath(
            $client,
            '/select-tenant',
            'Un POST sin CSRF no debe activar el negocio.'
        );

        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'Solicitud inválida.');
        $this->assertSelectorTextContains('header nav', 'Demo', 'El negocio activo no cambió.');
    }

    public function testOnboardingCreatesBusinessAndActivatesIt(): void
    {
        $client = $this->createSharedKernelClient();
        $user = $this->seedUserWithoutMemberships();

        $this->loginAs($client, 'sin-negocio@demo.local', 'demo');
        $crawler = $client->request('GET', '/onboarding');
        $this->assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Crear negocio')->form(), [
            'onboarding_form' => [
                'name' => 'Mi Negocio',
                'slug' => 'mi-negocio',
            ],
        ]);
        $this->assertRedirectPath($client, '/');

        $tenant = static::getContainer()->get(TenantRepositoryInterface::class)
            ->findBySlug('mi-negocio');
        $this->assertNotNull($tenant, 'El negocio debe quedar persistido.');

        $memberships = static::getContainer()->get(TenantMembershipRepositoryInterface::class)
            ->findByUser($user);
        $this->assertCount(1, $memberships, 'El creador debe quedar como miembro de su negocio.');
        $this->assertSame($tenant->getId(), $memberships[0]->getTenant()->getId());
        $this->assertSame(TenantMembership::ROLE_ADMIN, $memberships[0]->getRole());

        // Con el negocio ya activo en la sesión, el dashboard responde.
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('header nav', 'Mi Negocio');
    }

    public function testOnboardingDerivesSlugFromNameWhenLeftEmpty(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedUserWithoutMemberships();

        $this->loginAs($client, 'sin-negocio@demo.local', 'demo');
        $crawler = $client->request('GET', '/onboarding');

        $client->submit($crawler->selectButton('Crear negocio')->form(), [
            'onboarding_form' => ['name' => 'Kiosco El Sol'],
        ]);
        $this->assertRedirectPath($client, '/');

        $tenant = static::getContainer()->get(TenantRepositoryInterface::class)
            ->findBySlug('kiosco-el-sol');
        $this->assertNotNull($tenant, 'El slug debe derivarse del nombre.');
    }

    public function testOnboardingRejectsSlugAlreadyInUse(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $user = $this->seedUserWithoutMemberships();

        $this->loginAs($client, 'sin-negocio@demo.local', 'demo'); // sin membresías
        $crawler = $client->request('GET', '/onboarding');

        $client->submit($crawler->selectButton('Crear negocio')->form(), [
            'onboarding_form' => [
                'name' => 'Otro Negocio',
                'slug' => 'demo',
            ],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString(
            'Ese slug ya está en uso',
            $client->getResponse()->getContent()
        );

        $memberships = static::getContainer()->get(TenantMembershipRepositoryInterface::class);
        $this->assertSame(
            [],
            $memberships->findByUser($user),
            'Un slug tomado no debe crear membership.'
        );
    }

    public function testLogoutClearsTheActiveBusiness(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();

        $this->loginAsDemoAdmin($client);
        $client->followRedirect();

        // Logout real vía el formulario del nav (CSRF incluido).
        $client->submit($client->getCrawler()->selectButton('Cerrar sesión')->form());
        $this->assertRedirectPath($client, '/login', 'El logout debe ir al login (security.yaml).');

        // Sin sesión, la raíz vuelve a pedir login.
        $client->request('GET', '/');
        $this->assertRedirectPath($client, '/login');
    }

    /**
     * Afirma que la última respuesta es un redirect cuyo Location termina
     * en $path (tolera URL absoluta o relativa, que difieren según si lo
     * generó form_login, un controller o el listener).
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

    /**
     * Agrega una membresía ROLE_ADMIN del usuario a otro tenant y la
     * devuelve (el id es lo que se usa en el POST del selector).
     */
    private function addMembership(AppUser $user, string $slug, ?string $name = null): TenantMembership
    {
        $tenant = new Tenant();
        $tenant->setName($name ?? ucfirst($slug));
        $tenant->setSlug($slug);

        $membership = new TenantMembership($user, $tenant);
        $membership->setRole(TenantMembership::ROLE_ADMIN);

        $user->addMembership($membership);
        $this->em->persist($tenant);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    /**
     * Usuario con hash real y SIN membresías: el caso del onboarding.
     */
    private function seedUserWithoutMemberships(string $email = 'sin-negocio@demo.local'): AppUser
    {
        $user = new AppUser();
        $user->setEmail($email);
        $user->setRoles(['ROLE_ADMIN']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'demo'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seedInvestmentFor(Tenant $tenant, AppUser $user, string $code): Investment
    {
        $investment = new Investment();
        $investment->setTenant($tenant);
        $investment->setCreatedBy($user);
        $investment->setCode($code);
        $investment->setInvestmentDate(new \DateTimeImmutable('2026-10-01'));
        $investment->setBaseCurrency('ARS');
        $investment->setStatus(Investment::STATUS_OPEN);

        $this->em->persist($investment);
        $this->em->flush();

        return $investment;
    }
}
