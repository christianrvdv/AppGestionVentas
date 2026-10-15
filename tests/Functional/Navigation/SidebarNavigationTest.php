<?php

declare(strict_types=1);

namespace App\Tests\Functional\Navigation;

use App\Tests\Functional\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Contrato de la navegación sidebar + topbar (SPEC-sidebar-navegacion).
 *
 * Estos tests son la red de seguridad de refactorizar parciales de la
 * base: un solo nav principal, aria-current="page" como único indicador
 * de sección activa, y los selectores que dependen otros tests
 * (header nav con el negocio, header a[href="/admin"]).
 */
final class SidebarNavigationTest extends FunctionalTestCase
{
    public function testSidebarRendersFourGroupsWithTwelveLinks(): void
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertCount(
            1,
            $crawler->filter('nav[aria-label="Navegación principal"]'),
            'El sidebar debe ser el ÚNICO nav con esa etiqueta (PaymentIndexTest cuenta 1 elemento).'
        );

        $groups = $crawler
            ->filter('nav[aria-label="Navegación principal"] h2')
            ->each(fn ($heading): string => trim($heading->text()));

        $this->assertSame(['Operación', 'Catálogo', 'Reportes', 'Sistema'], $groups);

        $expected = [
            '/',
            '/inversiones',
            '/ventas',
            '/pagos',
            '/productos',
            '/clientes',
            '/tasas-usd',
            '/reportes/ganancias',
            '/reportes/inversiones',
            '/reportes/productos',
            '/reportes/revaluacion',
            '/configuracion',
        ];

        $hrefs = $crawler
            ->filter('nav[aria-label="Navegación principal"] a')
            ->each(fn ($link): ?string => $link->attr('href'));

        $this->assertSame($expected, $hrefs, 'Los 12 destinos del sidebar, en el orden documentado en la spec.');
    }

    public function testDashboardMarksItselfAsCurrentSection(): void
    {
        $crawler = $this->requestAsDemoAdmin('/');

        $current = $crawler->filter('nav[aria-label="Navegación principal"] [aria-current="page"]');

        $this->assertCount(1, $current, 'Exactamente una sección activa por página.');
        $this->assertSame('/', $current->attr('href'));
        $this->assertStringContainsString('nav-link-active', (string) $current->attr('class'));
    }

    public function testSectionRouteMarksItsSidebarLinkAsCurrent(): void
    {
        $crawler = $this->requestAsDemoAdmin('/ventas');

        $current = $crawler->filter('nav[aria-label="Navegación principal"] [aria-current="page"]');

        $this->assertCount(1, $current);
        $this->assertSame('/ventas', $current->attr('href'));
        $this->assertStringContainsString('nav-link-active', (string) $current->attr('class'));
    }

    public function testReportRouteMarksItsSidebarLinkAsCurrent(): void
    {
        $crawler = $this->requestAsDemoAdmin('/reportes/ganancias');

        $current = $crawler->filter('nav[aria-label="Navegación principal"] [aria-current="page"]');

        $this->assertCount(1, $current);
        $this->assertSame('/reportes/ganancias', $current->attr('href'));
    }

    public function testTopbarShowsActiveTenantAccountMenuAndLogout(): void
    {
        $crawler = $this->requestAsDemoAdmin('/');

        $topbar = $crawler->filter('header nav[aria-label="Acciones de la cuenta"]');

        $this->assertCount(1, $topbar, 'El topbar es un header nav (TenantSelectionTest depende de eso).');
        $this->assertStringContainsString('Demo', $topbar->text(), 'Nombre del negocio activo visible.');
        $this->assertStringContainsString('admin@demo.local', $topbar->text(), 'Trigger del user menu = email.');

        $this->assertCount(1, $topbar->filter('a[href="/select-tenant"]'), 'Selector de negocio.');
        $this->assertCount(1, $topbar->filter('a[href="/cambiar-password"]'), 'Cambiar contraseña navegable.');
        $this->assertCount(1, $topbar->filter('form[action="/logout"]'), 'Logout dentro del user menu.');
        $this->assertCount(
            1,
            $topbar->filter('form[action="/logout"] input[name="_csrf_token"]'),
            'Logout con token CSRF como hasta ahora.'
        );
    }

    public function testDrawerExposesPanelTogglesAndOverlay(): void
    {
        $crawler = $this->requestAsDemoAdmin('/');

        $this->assertCount(1, $crawler->filter('[data-controller="sidebar"]'), 'Orquestador del layout.');
        $this->assertCount(1, $crawler->filter('#sidebar-panel[data-sidebar-target="panel"]'));
        $this->assertCount(1, $crawler->filter('#sidebar-overlay[data-sidebar-target="overlay"]'));
        $overlayClasses = explode(' ', (string) $crawler->filter('#sidebar-overlay')->attr('class'));
        $this->assertContains('hidden', $overlayClasses, 'El backdrop arranca oculto (sin flash).');

        $openToggles = $crawler->filter('button[data-sidebar-toggle="open"]');
        $this->assertCount(
            2,
            $openToggles,
            'Hamburguesa (topbar) y X de cierre (dentro del cajón): comparten estado del mismo panel.'
        );
        $openToggles->each(function (Crawler $button): void {
            $this->assertSame('false', $button->attr('aria-expanded'), 'El cajón arranca cerrado.');
            $this->assertSame('sidebar-panel', $button->attr('aria-controls'));
        });

        $collapse = $crawler->filter('button[data-sidebar-toggle="collapse"]');
        $this->assertCount(1, $collapse);
        $this->assertSame('true', $collapse->attr('aria-expanded'), 'El sidebar arranca expandido.');
        $this->assertSame('sidebar-panel', $collapse->attr('aria-controls'));
    }

    public function testEverySidebarLinkHasAnAccessibleNameVisibleEvenWhenCollapsed(): void
    {
        $crawler = $this->requestAsDemoAdmin('/');

        $links = $crawler->filter('nav[aria-label="Navegación principal"] a');

        $this->assertCount(12, $links);

        $links->each(function (Crawler $link): void {
            $label = $link->filter('span.sidebar-label');
            $svg = $link->filter('svg');

            $this->assertCount(1, $label, 'Cada link tiene .sidebar-label (nombre accesible en modo iconos).');
            $this->assertNotSame('', trim($label->text()), 'El label no puede quedar vacío.');
            $this->assertCount(1, $svg, 'Cada link tiene icono decorativo.');
            $this->assertSame('true', $svg->attr('aria-hidden'), 'Icono oculto para AT (decorativo).');
        });
    }

    public function testLoginPageRendersWithoutSidebarOrTopbar(): void
    {
        $client = $this->createSharedKernelClient();

        $crawler = $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('#sidebar-panel'), 'Sin sesión no hay sidebar.');
        $this->assertCount(0, $crawler->filter('header'), 'Sin sesión no hay topbar.');
        $this->assertCount(0, $crawler->filter('[data-controller="sidebar"]'));
    }

    /**
     * GET autenticado como el admin demo (mismo patrón que el resto de
     * los tests funcionales de index).
     */
    private function requestAsDemoAdmin(string $path): Crawler
    {
        $client = $this->createSharedKernelClient();
        $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($client);

        $crawler = $client->request('GET', $path);
        $this->assertResponseIsSuccessful();

        return $crawler;
    }
}
