<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Sale;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Escenario 9: cross-tenant safety del panel admin (Fase 6.1).
 *
 * El super_admin corre con el TenantFilter deshabilitado (ADR-0016), así
 * que las vistas de negocio no se apoyan en el filtro para aislar datos.
 * /inversiones exige un tenant activo en la sesión y le responde 403 en
 * lugar de volcar inversiones de todos los negocios.
 *
 * El inverso también se afirma acá: el detalle /admin/tenants/{id} lleva
 * el tenantId explícito en la query, así que los datos de un negocio no
 * se cuelan en las métricas de otro aunque el SQLFilter esté apagado.
 */
final class AdminCrossTenantTest extends FunctionalTestCase
{
    public function testInvestmentsPageIsForbiddenForSuperAdminWithoutTenant(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();
        $this->seedForeignSale(); // hay inversiones reales en la BD

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        $client->request('GET', '/inversiones');
        $this->assertResponseStatusCodeSame(403);

        $client->request('GET', '/ventas');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTenantDetailMetricsAreScopedToThatTenant(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedSuperAdmin();

        ['tenant' => $demo] = $this->seedDemoTenantAndAdmin();

        $demoAdmin = $this->em->getRepository(\App\Entity\AppUser::class)
            ->findOneBy(['email' => 'admin@demo.local']);
        $this->assertNotNull($demoAdmin);

        ['investment' => $demoInvestment] = $this->seedConfirmedInvestmentWithStock(
            $demoAdmin,
            new \DateTimeImmutable('2026-02-01'),
            'Inversión demo'
        );
        $this->seedSale($demoInvestment, $demoAdmin, quantity: 3, unitPrice: '200.00');

        // Venta ajena: si la query no llevara el tenantId explícito, el
        // monto de Demo la incluiría (el SQLFilter está apagado aquí).
        $this->seedForeignSale();

        $this->loginAs($client, 'super@demo.local', 'super1234');
        $client->followRedirect();

        $crawler = $client->request('GET', sprintf('/admin/tenants/%d', $demo->getId()));
        $this->assertResponseIsSuccessful();

        $this->assertSame('1', $crawler->filter('#metrica-inversiones')->text());
        $this->assertSame('1', $crawler->filter('#metrica-ventas')->text());
        $this->assertSame(
            '600,00',
            $crawler->filter('#metrica-monto-vendido')->text(),
            'El monto de Demo debe ser 3×200, sin la venta de Ajeno.'
        );
    }
}
