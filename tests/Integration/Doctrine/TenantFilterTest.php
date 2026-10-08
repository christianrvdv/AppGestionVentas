<?php

namespace App\Tests\Integration\Doctrine;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\Product;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [FIX-5] SQLFilter::getParameter() devuelve el valor ENTRECOMILLADO
 * (Connection::quote(), p.ej. "'5'"). Un cast directo a int de "'5'"
 * da 0 y bloqueaba TODAS las filas de cualquier consulta filtrada.
 *
 * El filtro ahora recorta las comillas, castea a int y usa %d en la
 * interpolación, de modo que:
 *   - el valor real se aplica (no 0),
 *   - un valor no numérico no puede inyectar SQL,
 *   - null / '' / '0' caen en la rama "0 filas".
 */
final class TenantFilterTest extends IntegrationTestCase
{
    public function test_filtro_restringe_a_las_filas_del_tenant_configurado(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');

        $productA = $this->createProduct($tenantA, 'Producto A');
        $productB = $this->createProduct($tenantB, 'Producto B');

        $filter = $this->em->getFilters()->enable(TenantFilter::NAME);
        $filter->setParameter('tenantId', (string) $tenantA->getId(), 'integer');

        $products = $this->em->getRepository(Product::class)->findAll();

        $ids = array_map(static fn (Product $p): int => $p->getId(), $products);

        $this->assertCount(1, $ids, 'El filtro debe devolver solo los productos del tenant activo.');
        $this->assertContains($productA->getId(), $ids);
        $this->assertNotContains($productB->getId(), $ids);
    }

    public function test_filtro_con_parametro_cambiado_no_deja_ver_el_otro_tenant(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');

        $productA = $this->createProduct($tenantA, 'Producto A');
        $productB = $this->createProduct($tenantB, 'Producto B');

        $filter = $this->em->getFilters()->enable(TenantFilter::NAME);

        $filter->setParameter('tenantId', (string) $tenantB->getId(), 'integer');
        $idsB = $this->productIds();
        $this->assertContains($productB->getId(), $idsB);
        $this->assertNotContains($productA->getId(), $idsB);

        $filter->setParameter('tenantId', (string) $tenantA->getId(), 'integer');
        $idsA = $this->productIds();
        $this->assertContains($productA->getId(), $idsA);
        $this->assertNotContains($productB->getId(), $idsA);
    }

    /**
     * [FIX-5] El filtro debe interpolar un ENTERO, no el valor que
     * getParameter() devuelve entrecomillado ("'5'").
     *
     * Se comprueba sobre el SQL generado: antes la cláusula quedaba
     * "tenant_id = '5'" (SQL crudo a partir de la salida de quote()) y la
     * rama "sin tenant" solo cubría la cadena literal '0', no el int 0.
     */
    public function test_el_filtro_interpola_un_entero_y_no_el_valor_entrecomillado(): void
    {
        $tenant = $this->createTenant();
        $this->createProduct($tenant, 'Producto A');

        $filter = $this->em->getFilters()->enable(TenantFilter::NAME);
        $filter->setParameter('tenantId', (string) $tenant->getId(), 'integer');

        $sql = $this->productFilterSql();

        $this->assertStringContainsString(
            sprintf('tenant_id = %d', $tenant->getId()),
            $sql,
            'La restricción debe usar el valor entero (sprintf %d).'
        );
        $this->assertStringNotContainsString(
            sprintf("tenant_id = '%d'", $tenant->getId()),
            $sql,
            'La restricción NO debe intercalar el valor entrecomillado.'
        );
    }

    /**
     * Defensa contra inyección: un valor no numérico se trunca a su
     * prefijo entero y se interpola con %d, nunca como SQL crudo.
     * Si el valor se concatenara sin sanitizar, "1 OR 1 = 1" devolvería
     * TODAS las filas; con el casteo solo debe devolver el tenant 1.
     */
    public function test_filtro_con_valor_no_numerico_no_produce_sql_inyectado(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');
        $productA = $this->createProduct($tenantA, 'Producto A');
        $productB = $this->createProduct($tenantB, 'Producto B');

        $filter = $this->em->getFilters()->enable(TenantFilter::NAME);
        $filter->setParameter('tenantId', sprintf('%d OR 1 = 1', $tenantA->getId()), 'string');

        $sql = $this->productFilterSql();
        $this->assertStringNotContainsString('OR 1 = 1', $sql, 'La parte inyectada no debe llegar al SQL.');

        $ids = $this->productIds();

        $this->assertContains($productA->getId(), $ids, 'El prefijo entero (1) debe aplicarse como filtro.');
        $this->assertNotContains($productB->getId(), $ids, 'La parte inyectada (OR 1 = 1) no debe filtrar.');
    }

    /**
     * Sin parámetro el filtro no restringe (contexto CLI / migraciones).
     */
    public function test_filtro_sin_parametro_no_restringe(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');
        $this->createProduct($tenantA, 'Producto A');
        $this->createProduct($tenantB, 'Producto B');

        $this->em->getFilters()->enable(TenantFilter::NAME);

        $this->assertCount(2, $this->productIds());
    }

    /**
     * Valor '0' (sin tenant activo): 0 filas, nunca todas.
     */
    public function test_filtro_con_tenant_cero_no_devuelve_filas(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $this->createProduct($tenantA, 'Producto A');

        $filter = $this->em->getFilters()->enable(TenantFilter::NAME);
        $filter->setParameter('tenantId', '0', 'string');

        $this->assertSame([], $this->productIds());
    }

    /**
     * SQL que resultaría de una consulta de productos con el filtro activo.
     */
    private function productFilterSql(): string
    {
        return $this->em->getRepository(Product::class)
            ->createQueryBuilder('p')
            ->getQuery()
            ->getSQL();
    }

    /**
     * @return int[]
     */
    private function productIds(): array
    {
        return array_map(
            static fn (Product $p): int => $p->getId(),
            $this->em->getRepository(Product::class)->findAll()
        );
    }
}
