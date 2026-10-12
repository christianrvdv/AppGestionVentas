<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Listado global de usuarios (Fase 6.1, escenario 4).
 *
 * /admin/users lista TODAS las identidades del sistema con sus
 * membresías (fetch-join), paginada y filtrable por email, por negocio
 * (tenant EXPLÍCITO en la cláusula) y por rol GLOBAL (pre-lookup DBAL
 * sobre roles::text, porque DQL no puede hacer LIKE sobre jsonb en
 * PostgreSQL).
 *
 * Universo sembrado (13 usuarios): super (global), admin@demo (Demo),
 * doble@demo (Demo + Ajeno), admin@ajeno (Ajeno) y 9 extras sin
 * membresías.
 */
final class AdminUserIndexTest extends FunctionalTestCase
{
    public function testUserListIsPaginated(): void
    {
        ['client' => $client] = $this->seedAndLogin();

        $crawler = $client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $this->assertSame('13', $crawler->filter('#valor-total-usuarios')->text());
        $this->assertSame(
            10,
            $crawler->filter('tbody tr')->count(),
            'La primera página debe mostrar 10 filas.'
        );

        $crawler = $client->request('GET', '/admin/users?page=2');
        $this->assertResponseIsSuccessful();
        $this->assertSame(3, $crawler->filter('tbody tr')->count());
    }

    public function testUserListFiltersByEmail(): void
    {
        ['client' => $client] = $this->seedAndLogin();

        $crawler = $client->request('GET', '/admin/users', ['q' => 'extra']);
        $this->assertSame('9', $crawler->filter('#valor-total-usuarios')->text());

        // Mayúsculas/minúsculas sin importar.
        $crawler = $client->request('GET', '/admin/users', ['q' => 'ADMIN@DEMO']);
        $this->assertSame('1', $crawler->filter('#valor-total-usuarios')->text());
        $this->assertStringContainsString(
            'admin@demo.local',
            $crawler->filter('tbody tr')->text()
        );
    }

    public function testUserListFiltersByTenant(): void
    {
        ['client' => $client, 'ids' => $ids] = $this->seedAndLogin();

        // Demo: admin@demo + doble@demo. Ajeno: admin@ajeno + doble@demo.
        $crawler = $client->request('GET', '/admin/users', ['tenant' => $ids['demo']]);
        $this->assertSame('2', $crawler->filter('#valor-total-usuarios')->text());

        $crawler = $client->request('GET', '/admin/users', ['tenant' => $ids['ajeno']]);
        $this->assertSame('2', $crawler->filter('#valor-total-usuarios')->text());
        $this->assertStringContainsString('admin@ajeno.local', $crawler->filter('tbody tr')->text());

        // Un negocio sin usuarios no debe inventar filas.
        $crawler = $client->request('GET', '/admin/users', ['tenant' => $ids['vacio']]);
        $this->assertSame('0', $crawler->filter('#valor-total-usuarios')->text());
    }

    public function testUserListFiltersByGlobalRole(): void
    {
        ['client' => $client] = $this->seedAndLogin();

        // Rol GLOBAL guardado en app_user.roles (no de membresía).
        $crawler = $client->request('GET', '/admin/users', ['rol' => 'ROLE_SUPER_ADMIN']);
        $this->assertSame('1', $crawler->filter('#valor-total-usuarios')->text());
        $this->assertStringContainsString('super@demo.local', $crawler->filter('tbody tr')->text());

        $crawler = $client->request('GET', '/admin/users', ['rol' => 'ROLE_ADMIN']);
        $this->assertSame('2', $crawler->filter('#valor-total-usuarios')->text());

        $crawler = $client->request('GET', '/admin/users', ['rol' => 'ROLE_USER']);
        $this->assertSame('1', $crawler->filter('#valor-total-usuarios')->text());

        // Un rol desconocido no filtra (y no rompe): solo se admite la
        // allow-list del controller.
        $crawler = $client->request('GET', '/admin/users', ['rol' => 'ROLE_INVENTADO']);
        $this->assertSame('13', $crawler->filter('#valor-total-usuarios')->text());
    }

    public function testUserRowsShowMemberships(): void
    {
        ['client' => $client, 'ids' => $ids] = $this->seedAndLogin();

        $crawler = $client->request('GET', '/admin/users', ['q' => 'doble']);
        $dobleRow = $crawler->filter(sprintf('#usuario-%d', $ids['doble']));
        $this->assertSame(1, $dobleRow->count());
        $this->assertSame('2', $dobleRow->filter('.cel-negocios')->text());
        $this->assertStringContainsString('Demo', $dobleRow->text());
        $this->assertStringContainsString('Ajeno', $dobleRow->text());

        $crawler = $client->request('GET', '/admin/users', ['q' => 'extra-01']);
        $extraRow = $crawler->filter(sprintf('#usuario-%d', $ids['extras'][0]));
        $this->assertSame('0', $extraRow->filter('.cel-negocios')->text());
        $this->assertStringContainsString('Sin negocios', $extraRow->text());
    }

    /**
     * @return array{
     *     client: KernelBrowser,
     *     ids: array{demo: int, ajeno: int, vacio: int, doble: int, extras: list<int>}
     * }
     */
    private function seedAndLogin(): array
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();

        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();
        $sale = $this->seedForeignSale();
        $ajeno = $sale->getTenant();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $doble = new AppUser();
        $doble->setEmail('doble@demo.local');
        $doble->setRoles([TenantMembership::ROLE_USER]);
        $doble->setPasswordHash($hasher->hashPassword($doble, 'demo'));
        foreach ([$demo, $ajeno] as $tenant) {
            $membership = new TenantMembership($doble, $tenant);
            $membership->setRole(TenantMembership::ROLE_USER);
            $doble->addMembership($membership);
            $this->em->persist($membership);
        }
        $this->em->persist($doble);

        $vacio = new Tenant();
        $vacio->setName('Vacío');
        $vacio->setSlug('vacio');
        $vacio->setIsActive(false);
        $this->em->persist($vacio);

        $extras = [];
        for ($i = 1; $i <= 9; ++$i) {
            $extra = new AppUser();
            $extra->setEmail(sprintf('extra-%02d@test.local', $i));
            $extra->setRoles([]);
            $extra->setPasswordHash($hasher->hashPassword($extra, 'demo'));
            $this->em->persist($extra);
            $extras[] = $extra;
        }

        $this->em->flush();

        $ids = [
            'demo' => $demo->getId(),
            'ajeno' => $ajeno->getId(),
            'vacio' => $vacio->getId(),
            'doble' => $doble->getId(),
            // Se crearon en orden: el primero es extra-01@test.local.
            'extras' => array_map(
                static fn (AppUser $user): int => $user->getId(),
                $extras
            ),
        ];

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        return ['client' => $client, 'ids' => $ids];
    }
}
