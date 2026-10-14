<?php

declare(strict_types=1);

namespace App\Tests\Functional\Report;

use App\Tests\Functional\FunctionalTestCase;

/**
 * Smoke de las 4 páginas de /reportes/*.
 *
 * Regresión del bug de Twig: report/base.html.twig usaba `request` como
 * variable global, que Twig 3 / Symfony 6+ ya no expone (solo `app.request`),
 * y las cuatro páginas reventaban con "Variable request does not exist".
 * Todas extienden de ese base, así que un GET a cada una cubre el fix.
 */
final class ReportPagesTest extends FunctionalTestCase
{
    /**
     * @return list<string>
     */
    public static function reportUrls(): array
    {
        return [
            '/reportes/ganancias',
            '/reportes/inversiones',
            '/reportes/productos',
            '/reportes/revaluacion',
        ];
    }

    public function testReportPagesRenderWithoutData(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        foreach (self::reportUrls() as $url) {
            $client->request('GET', $url);

            $this->assertResponseIsSuccessful(sprintf('La página %s debe renderizar 200.', $url));
        }
    }

    /**
     * Con ventas e inversión reales los informes tienen filas, y la petición
     * lleva query string para ejercitar el link "Limpiar" del base
     * (app.request.requestUri|split('?')[0]).
     */
    public function testReportPagesRenderWithSeededDataAndQueryString(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $this->seedSale($investment, $user);

        $this->loginAsDemoAdmin($client);

        foreach (self::reportUrls() as $url) {
            $client->request('GET', $url, ['from' => '2026-01-01', 'to' => '2026-12-31']);

            $this->assertResponseIsSuccessful(sprintf('La página %s debe renderizar 200 con datos.', $url));
            $this->assertSelectorExists('a.btn-secondary', 'El link "Limpiar" del base debe renderizar.');
        }
    }
}
