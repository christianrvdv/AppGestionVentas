<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Service\Investment\InvestmentCreationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base de los tests funcionales (HTTP) de la capa de presentación.
 *
 * Usa el mismo SQLite in-memory que la suite de integración
 * (phpunit.dist.xml fuerza DATABASE_URL). Como cada petición con
 * kernel reboot crearía una conexión nueva (BD vacía), el client
 * comparte el kernel con disableReboot(): misma conexión, misma BD.
 *
 * En esta versión del framework, WebTestCase::createClient() arranca
 * el kernel él mismo (no se puede bootKernel() antes), así que el
 * client se crea primero y el esquema se recrea después sobre su
 * contenedor.
 *
 * ATENCIÓN: al compartir kernel entre peticiones, el TenantFilter
 * puede quedar habilitado entre requests (en producción el kernel se
 * reinicia por request). Es justamente lo que el AppUserProvider
 * neutraliza alrededor de sus lookups; los tests lo ejercitan.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected EntityManagerInterface $em;

    /**
     * Client que NO reboota el kernel entre peticiones: conserva la
     * conexión SQLite in-memory (y su contenido) durante el test.
     * El esquema queda limpio y vacío listo para sembrar datos.
     */
    protected function createSharedKernelClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->recreateSchema();

        return $client;
    }

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
     * Crea el tenant "demo" y su admin con hash real de contraseña,
     * usando el hasher de la aplicación (barato en test, ver
     * security.yaml when@test).
     *
     * @return array{tenant: Tenant, user: AppUser}
     */
    protected function seedDemoTenantAndAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Demo');
        $tenant->setSlug('demo');

        $user = new AppUser();
        $user->setTenant($tenant);
        $user->setEmail('admin@demo.local');
        $user->setRoles(['ROLE_ADMIN']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'demo'));

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->flush();

        return ['tenant' => $tenant, 'user' => $user];
    }

    /**
     * Autentica vía el formulario real de /login (flujo completo:
     * CSRF, provider, checker, sesión).
     */
    protected function loginAsDemoAdmin(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Iniciar sesión')->form();

        $client->submit($form, [
            'tenant_slug' => 'demo',
            'email' => 'admin@demo.local',
            'password' => 'demo',
        ]);
    }

    /**
     * Crea una inversión en borrador (OPEN) con los servicios del dominio,
     * asignando el TenantContext que los servicios esperan (en web lo hace
     * el TenantFilterListener; aquí se simula para el seed).
     */
    protected function seedInvestment(AppUser $user, ?\DateTimeImmutable $date = null, ?string $description = null): Investment
    {
        $context = static::getContainer()->get(TenantContext::class);
        $previous = $context->getTenantId();
        $context->setTenantId($user->getTenant()->getId());

        try {
            $investment = static::getContainer()
                ->get(InvestmentCreationService::class)
                ->createDraft($user, $date ?? new \DateTimeImmutable('today'), $description);

            $this->em->persist($investment);
            $this->em->flush();

            return $investment;
        } finally {
            $context->setTenantId($previous);
        }
    }

    protected function seedProduct(Tenant $tenant, string $name, ?string $sku = null): Product
    {
        $product = new Product();
        $product->setTenant($tenant);
        $product->setName($name);
        $product->setSku($sku);

        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    protected function addItemToInvestment(Investment $investment, Product $product, int $quantity = 1, string $unitCost = '100.00'): InvestmentItem
    {
        $context = static::getContainer()->get(TenantContext::class);
        $previous = $context->getTenantId();
        $context->setTenantId($investment->getTenant()->getId());

        try {
            $item = static::getContainer()
                ->get(InvestmentItemService::class)
                ->addItem($investment, $product, $quantity, $unitCost);

            $this->em->flush();

            return $item;
        } finally {
            $context->setTenantId($previous);
        }
    }
}
