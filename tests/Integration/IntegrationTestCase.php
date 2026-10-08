<?php

namespace App\Tests\Integration;

use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base de los tests de integración (capa de dominio sobre BD real del
 * driver de test: SQLite in-memory, ver phpunit.dist.xml).
 *
 * Cada test arranca con un esquema recién creado y limpio: se dropean
 * todas las tablas y se vuelven a crear desde los metadatos de Doctrine.
 * Así los tests son independientes de migraciones y del orden de
 * ejecución, y de paso validan que el mapping completo es creable.
 *
 * ATENCIÓN: el bundle DAMA doctrine-test-bundle NO tiene su extensión
 * PHPUnit activa en phpunit.dist.xml, por lo que NO hay rollback
 * automático entre tests. Cualquier test debe partir de esquema limpio
 * (garantizado por setUp()) y no asumir datos de tests anteriores.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tenantContext = $container->get(TenantContext::class);

        $this->recreateSchema();
    }

    /**
     * Dropea todas las tablas del esquema de test y las crea de nuevo
     * a partir de los metadatos de las entidades.
     */
    private function recreateSchema(): void
    {
        $connection = $this->em->getConnection();
        $schemaManager = $connection->createSchemaManager();

        foreach ($schemaManager->listTableNames() as $tableName) {
            $schemaManager->dropTable($tableName);
        }

        $tool = new SchemaTool($this->em);
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * Persiste un tenant y lo deja como contexto activo del test
     * (SettingsService lanza LogicException si el contexto está vacío).
     */
    protected function createTenant(string $slug = 'acme'): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Acme');
        $tenant->setSlug($slug);

        $this->em->persist($tenant);
        $this->em->flush();

        $this->tenantContext->setTenantId($tenant->getId());

        return $tenant;
    }

    protected function createAppUser(Tenant $tenant, string $email = 'admin@example.com'): AppUser
    {
        $user = new AppUser();
        $user->setTenant($tenant);
        $user->setEmail($email);
        $user->setPasswordHash('hash-test');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createProduct(Tenant $tenant, string $name = 'Producto'): Product
    {
        $product = new Product();
        $product->setTenant($tenant);
        $product->setName($name);
        $product->setSku('SKU-' . strtoupper($name));

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    protected function createCustomer(Tenant $tenant, string $name = 'Cliente'): Customer
    {
        $customer = new Customer();
        $customer->setTenant($tenant);
        $customer->setName($name);

        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
    }

    /**
     * Inversión en estado OPEN NO confirmada, lista para recibir items
     * y gastos. Para tests que necesiten inventario real se llama a
     * confirmInvestment() después de persistir los items.
     */
    protected function createInvestment(Tenant $tenant, AppUser $user, string $code = 'INV-TEST'): Investment
    {
        $investment = new Investment();
        $investment->setTenant($tenant);
        $investment->setCreatedBy($user);
        $investment->setCode($code);
        $investment->setInvestmentDate(new \DateTimeImmutable('2026-01-01'));
        $investment->setBaseCurrency('USD');

        $this->em->persist($investment);
        $this->em->flush();

        return $investment;
    }

    /**
     * Confirma la inversión con el servicio real (crea movimientos
     * PURCHASE + summary) y deja todo persistido.
     */
    protected function confirmInvestment(Investment $investment): void
    {
        $confirmation = static::getContainer()->get(InvestmentConfirmationService::class);
        $confirmation->confirm($investment);
        $this->em->flush();
    }

    /**
     * Carga una entidad desde cero (em::clear) para comprobar lo que
     * quedó realmente persistido y serializado.
     */
    protected function reload(object $entity): object
    {
        $class = get_class($entity);
        $id = $this->em->getClassMetadata($class)->getIdentifierValues($entity);

        $this->em->clear();

        return $this->em->getRepository($class)->find(array_values($id)[0]);
    }

    /**
     * Compara montos con precisión de 2 decimales.
     *
     * En SQLite (motor de tests) las columnas DECIMAL se guardan con
     * afinidad NUMERIC: '50.00' vuelve como '50' y assertSame falla. En
     * PostgreSQL los decimales se conservan, así que la comparación se
     * hace con bccomp y es válida en ambos motores.
     */
    protected function assertDecimalEquals(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(
            0,
            bccomp($expected, $actual, 2),
            sprintf(
                '%sSe esperaba "%s" y se obtuvo "%s".',
                $message !== '' ? $message . ' ' : '',
                $expected,
                $actual
            )
        );
    }
}
