<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Tenant;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Dashboard del panel global (Fase 6.1, alcance 1).
 *
 * /admin reemplaza al placeholder: muestra los contadores globales
 * (tenants activos, usuarios, membresías, inversiones, ventas) y los
 * links a las secciones del panel.
 *
 * Los contadores son conteos de filas crudos (el histórico completo,
 * inversiones canceladas y ventas anuladas incluidas); solo "tenants
 * activos" filtra por isActive.
 */
final class AdminDashboardTest extends FunctionalTestCase
{
    public function testDashboardShowsGlobalCounters(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();

        ['user' => $demoAdmin] = $this->seedDemoTenantAndAdmin();
        $this->seedInvestment($demoAdmin, new \DateTimeImmutable('2026-01-15'), 'Inversión demo');
        $this->seedForeignSale(); // tenant Ajeno: 1 usuario, 1 membresía, 1 inversión, 1 venta

        $paused = new Tenant();
        $paused->setName('Pausado');
        $paused->setSlug('pausado');
        $paused->setIsActive(false);
        $this->em->persist($paused);
        $this->em->flush();

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        $crawler = $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Administración');

        // Demo + Ajeno activos; Pausado (inactivo) no cuenta.
        $this->assertSame('2', $crawler->filter('#valor-tenants-activos')->text());
        // super@demo.local + admin@demo.local + admin@ajeno.local.
        $this->assertSame('3', $crawler->filter('#valor-usuarios')->text());
        // Demo (admin demo) + Ajeno (admin de seedForeignSale).
        $this->assertSame('2', $crawler->filter('#valor-membresias')->text());
        // Inversión demo + inversión de Ajeno.
        $this->assertSame('2', $crawler->filter('#valor-inversiones')->text());
        // La única venta sembrada es la de Ajeno.
        $this->assertSame('1', $crawler->filter('#valor-ventas')->text());
    }

    public function testDashboardLinksToSections(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        $crawler = $client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();

        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/admin/tenants"]')->count(),
            'El dashboard debe enlazar al listado de tenants.'
        );
        $this->assertGreaterThan(
            0,
            $crawler->filter('a[href="/admin/users"]')->count(),
            'El dashboard debe enlazar al listado de usuarios.'
        );
    }

    public function testNavShowsAdminLinkOnlyForSuperAdmin(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        $this->seedDemoTenantAndAdmin();

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            1,
            $crawler->filter('header a[href="/admin"]')->count(),
            'El nav del super_admin debe enlazar al panel /admin.'
        );

        $client->restart();
        $this->loginAs($client, 'admin@demo.local', 'demo');

        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            0,
            $crawler->filter('header a[href="/admin"]')->count(),
            'Un admin de negocio no debe ver el link al panel /admin.'
        );
    }
}
