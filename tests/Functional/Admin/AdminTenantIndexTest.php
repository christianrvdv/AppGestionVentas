<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Listado global de negocios (Fase 6.1, escenario 2).
 *
 * /admin/tenants es la vista del super_admin con TODOS los negocios (el
 * SQLFilter está deshabilitado para su rol), paginada con KnpPaginator y
 * filtrable por estado y por nombre/slug.
 *
 * Universo sembrado (13 tenants, 12 activos): Demo (con inversión),
 * Ajeno (con inversión y venta), Pausado (inactivo) y 10 extras para
 * forzar la paginación de 10 en 10.
 */
final class AdminTenantIndexTest extends FunctionalTestCase
{
    public function testTenantListIsPaginated(): void
    {
        ['client' => $client, 'ids' => $ids] = $this->seedAndLogin();

        $crawler = $client->request('GET', '/admin/tenants');
        $this->assertResponseIsSuccessful();
        $this->assertSame('13', $crawler->filter('#valor-total-tenants')->text());
        $this->assertSame(
            10,
            $crawler->filter('tbody tr')->count(),
            'La primera página debe mostrar 10 filas.'
        );
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href*="page=2"]')->count(),
            'Debe existir un enlace a la página 2.'
        );

        $crawler = $client->request('GET', '/admin/tenants?page=2');
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            3,
            $crawler->filter('tbody tr')->count(),
            'La segunda página cierra el listado (13 en total).'
        );
        $this->assertGreaterThan(
            0,
            $crawler->filter(sprintf('#tenant-%d', $ids['pausado']))->count(),
            'Pausado (último alfabéticamente) debe caer en la página 2.'
        );
    }

    public function testTenantListFiltersByStatus(): void
    {
        ['client' => $client, 'ids' => $ids] = $this->seedAndLogin();

        $crawler = $client->request('GET', '/admin/tenants', ['estado' => 'activo']);
        $this->assertSame('12', $crawler->filter('#valor-total-tenants')->text());
        $this->assertGreaterThan(0, $crawler->filter(sprintf('#tenant-%d', $ids['demo']))->count());
        $this->assertSame(
            0,
            $crawler->filter(sprintf('#tenant-%d', $ids['pausado']))->count(),
            'Un tenant inactivo no debe aparecer con el filtro activo.'
        );

        $crawler = $client->request('GET', '/admin/tenants', ['estado' => 'inactivo']);
        $this->assertSame('1', $crawler->filter('#valor-total-tenants')->text());
        $this->assertSame(1, $crawler->filter('tbody tr')->count());
        $this->assertGreaterThan(0, $crawler->filter(sprintf('#tenant-%d', $ids['pausado']))->count());

        // Un valor desconocido no filtra: no debe descartar filas ni romper.
        $crawler = $client->request('GET', '/admin/tenants', ['estado' => 'cualquiera']);
        $this->assertSame('13', $crawler->filter('#valor-total-tenants')->text());
    }

    public function testTenantListSearchesByNameOrSlug(): void
    {
        ['client' => $client, 'ids' => $ids] = $this->seedAndLogin();

        $crawler = $client->request('GET', '/admin/tenants', ['q' => 'pausa']);
        $this->assertSame('1', $crawler->filter('#valor-total-tenants')->text());
        $this->assertGreaterThan(0, $crawler->filter(sprintf('#tenant-%d', $ids['pausado']))->count());

        // Mayúsculas/minúsculas sin importar: busca contra nombre y slug.
        $crawler = $client->request('GET', '/admin/tenants', ['q' => 'DEMO']);
        $this->assertSame('1', $crawler->filter('#valor-total-tenants')->text());
        $this->assertGreaterThan(0, $crawler->filter(sprintf('#tenant-%d', $ids['demo']))->count());

        $crawler = $client->request('GET', '/admin/tenants', ['q' => 'zzz']);
        $this->assertSame('0', $crawler->filter('#valor-total-tenants')->text());
        $this->assertSame(0, $crawler->filter('tbody tr')->count());
        $this->assertGreaterThan(0, $crawler->filter('#lista-vacia')->count());
    }

    public function testTenantRowsShowPerTenantCounters(): void
    {
        ['client' => $client, 'ids' => $ids] = $this->seedAndLogin();

        $crawler = $client->request('GET', '/admin/tenants', ['q' => 'demo']);
        $demoRow = $crawler->filter(sprintf('#tenant-%d', $ids['demo']));
        $this->assertSame('1', $demoRow->filter('.cel-usuarios')->text());
        $this->assertSame('1', $demoRow->filter('.cel-inversiones')->text());
        $this->assertSame('0', $demoRow->filter('.cel-ventas')->text());

        $crawler = $client->request('GET', '/admin/tenants', ['q' => 'ajeno']);
        $ajenoRow = $crawler->filter(sprintf('#tenant-%d', $ids['ajeno']));
        $this->assertSame('1', $ajenoRow->filter('.cel-usuarios')->text());
        $this->assertSame('1', $ajenoRow->filter('.cel-inversiones')->text());
        $this->assertSame('1', $ajenoRow->filter('.cel-ventas')->text());
    }

    /**
     * 13 tenants: Demo (con inversión), Ajeno (inversión + venta),
     * Pausado (inactivo) y 10 extras activos.
     *
     * @return array{demo: int, ajeno: int, pausado: int}
     */
    private function seedTenantUniverse(): array
    {
        $this->seedSuperAdmin();

        ['tenant' => $demo, 'user' => $demoUser] = $this->seedDemoTenantAndAdmin();
        $this->seedInvestment($demoUser, new \DateTimeImmutable('2026-01-15'), 'Inversión demo');

        $foreignSale = $this->seedForeignSale();

        $pausado = new Tenant();
        $pausado->setName('Pausado');
        $pausado->setSlug('pausado');
        $pausado->setIsActive(false);
        $this->em->persist($pausado);

        for ($i = 1; $i <= 10; ++$i) {
            $extra = new Tenant();
            $extra->setName(sprintf('Negocio Extra %02d', $i));
            $extra->setSlug(sprintf('negocio-extra-%02d', $i));
            $this->em->persist($extra);
        }

        $this->em->flush();

        return [
            'demo' => $demo->getId(),
            'ajeno' => $foreignSale->getTenant()->getId(),
            'pausado' => $pausado->getId(),
        ];
    }

    /**
     * Kernel compartido primero (inicializa $em en la base), luego los
     * seeds y por último el login del super_admin.
     *
     * @return array{client: KernelBrowser, ids: array{demo: int, ajeno: int, pausado: int}}
     */
    private function seedAndLogin(): array
    {
        $client = $this->createSharedKernelClient();
        $ids = $this->seedTenantUniverse();
        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        return ['client' => $client, 'ids' => $ids];
    }
}
