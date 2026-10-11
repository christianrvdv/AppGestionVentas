<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\Sale;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentCreationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Sale\SaleService;
use App\Service\Sale\SaleVoidService;
use App\Service\TenantContext;
use App\Tests\Support\TestSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base de los tests funcionales (HTTP) de la capa de presentación.
 *
 * Usa la BD de test PostgreSQL (app_control_ventas_test, ver .env.test;
 *ADR-0011 unificó dev/test/prod en PostgreSQL). Como cada petición con
 * kernel reboot crearía una conexión nueva, el client comparte el
 * kernel con disableReboot(): misma conexión, misma BD.
 *
 * En esta versión del framework, WebTestCase::createClient() arranca
 * el kernel él mismo (no se puede bootKernel() antes), así que el
 * client se crea primero y sobre su contenedor se resuelven los
 * servicios. El esquema lo crea tests/bootstrap.php una vez por
 * corrida; el aislamiento entre tests es la transacción de DAMA.
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
     * conexión estática de DAMA (y su contenido) durante el test.
     * El esquema ya está creado por tests/bootstrap.php.
     */
    protected function createSharedKernelClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        return $client;
    }

    /**
     * Dropea y recrea el esquema completo. Solo para tests que lo
     * necesiten EXPLÍCITAMENTE: el aislamiento normal lo aporta la
     * transacción de DAMA (BEGIN/ROLLBACK por test).
     */
    protected function recreateSchema(): void
    {
        TestSchema::recreate($this->em);
    }

    /**
     * Compara montos con precisión de 2 decimales (bccomp), válida en
     * PostgreSQL y en cualquier motor: en SQLite una columna DECIMAL con
     * afinidad NUMERIC puede devolver '50.00' como '50', y en
     * PostgreSQL se conserva la escala ('50.00').
     */
    protected function assertDecimalEquals(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(
            0,
            bccomp($expected, $actual, 2),
            sprintf(
                '%sSe esperaba "%s" y se obtuvo "%s".',
                $message !== '' ? $message.' ' : '',
                $expected,
                $actual
            )
        );
    }

    /**
     * Crea el tenant "demo" y su admin con hash real de contraseña,
     * usando el hasher de la aplicación (barato en test, ver
     * security.yaml when@test).
     *
     * El usuario es global (ADR-0015): la pertenencia al tenant Demo
     * vive en la TenantMembership (ROLE_ADMIN, negocio por defecto), que
     * es lo que permite loguearse sin slug y que la sesión termine con
     * el tenant Demo activo.
     *
     * @return array{tenant: Tenant, user: AppUser, membership: TenantMembership}
     */
    protected function seedDemoTenantAndAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Demo');
        $tenant->setSlug('demo');

        $user = new AppUser();
        $user->setEmail('admin@demo.local');
        $user->setRoles(['ROLE_ADMIN']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'demo'));

        $membership = new TenantMembership($user, $tenant);
        $membership->setRole(TenantMembership::ROLE_ADMIN);
        $membership->setIsDefault(true);
        $user->addMembership($membership);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();

        return ['tenant' => $tenant, 'user' => $user, 'membership' => $membership];
    }

    /**
     * Autentica vía el formulario real de /login (flujo completo:
     * CSRF, provider, checker, sesión).
     *
     * Sin tenant_slug: el login es global (ADR-0015). Con una sola
     * membresía, TenantSessionListener activa ese negocio en la sesión
     * y el redirect cae en el dashboard.
     */
    protected function loginAsDemoAdmin(KernelBrowser $client): void
    {
        $this->loginAs($client, 'admin@demo.local', 'demo');
    }

    /**
     * Login genérico con credenciales arbitrarias (para usuarios sin
     * membresías u otros escenarios que no son el admin demo).
     */
    protected function loginAs(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Iniciar sesión')->form();

        $client->submit($form, [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * Tenant de prueba al que pertenece un usuario: su primera membresía
     * (los seeds de esta clase crean usuarios con una sola). Sirve para
     * arrancar el TenantContext en helpers, como antes hacía
     * $user->getTenant().
     */
    protected function tenantIdOf(AppUser $user): int
    {
        $membership = $user->getMemberships()->first();

        if (false === $membership) {
            throw new \LogicException(sprintf('El usuario %s no tiene membresías.', $user->getEmail()));
        }

        return $membership->getTenant()->getId();
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
        $context->setTenantId($this->tenantIdOf($user));

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

    protected function seedCustomer(Tenant $tenant, string $name, ?string $phone = null, ?string $email = null): Customer
    {
        $customer = new Customer();
        $customer->setTenant($tenant);
        $customer->setName($name);
        $customer->setPhone($phone);
        $customer->setEmail($email);

        $this->em->persist($customer);
        $this->em->flush();

        return $customer;
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

    /**
     * Inversión confirmada con stock para vender: crea el borrador, le
     * añade un producto y la confirma. Los movimientos PURCHASE que deja
     * la confirmación son el stock que SaleService valida, así que sin
     * este paso no se puede sembrar una venta real.
     *
     * @return array{investment: Investment, item: InvestmentItem}
     */
    protected function seedConfirmedInvestmentWithStock(
        AppUser $user,
        ?\DateTimeImmutable $date = null,
        ?string $description = null,
        int $quantity = 10,
        string $unitCost = '100.00'
    ): array {
        $investment = $this->seedInvestment($user, $date, $description);
        $product = $this->seedProduct($investment->getTenant(), 'Producto con stock', 'SKU-'.uniqid('', true));
        $item = $this->addItemToInvestment($investment, $product, $quantity, $unitCost);

        $this->runAsTenant(
            $investment->getTenant()->getId(),
            function () use ($investment): void {
                static::getContainer()->get(InvestmentConfirmationService::class)->confirm($investment);
                $this->em->flush();
            }
        );

        return ['investment' => $investment, 'item' => $item];
    }

    /**
     * Venta registrada por SaleService (el único punto de entrada del
     * dominio), con el TenantContext apuntado al tenant de la inversión.
     * Vende la primera línea del primer ítem de la inversión.
     */
    protected function seedSale(
        Investment $investment,
        AppUser $user,
        ?Customer $customer = null,
        ?\DateTimeImmutable $date = null,
        int $quantity = 5,
        string $unitPrice = '150.00'
    ): Sale {
        $item = $investment->getItems()->first();

        if (false === $item) {
            throw new \LogicException(sprintf(
                'La inversión %d no tiene ítems para vender.',
                $investment->getId()
            ));
        }

        return $this->runAsTenant(
            $investment->getTenant()->getId(),
            function () use ($investment, $item, $user, $customer, $date, $quantity, $unitPrice): Sale {
                return static::getContainer()
                    ->get(SaleService::class)
                    ->register(
                        new RegisterSaleCommand(
                            $investment->getId(),
                            $customer?->getId(),
                            $date ?? new \DateTimeImmutable('today'),
                            [new RegisterSaleLineCommand($item->getId(), $quantity, $unitPrice)]
                        ),
                        $user
                    );
            }
        );
    }

    /**
     * Venta anulada por SaleVoidService: la inversión queda sin ventas
     * vivas (reconocido y recuperación recalculados).
     */
    protected function seedVoidedSale(
        Investment $investment,
        AppUser $user,
        string $reason = 'Devolución del cliente',
        ?Customer $customer = null,
        ?\DateTimeImmutable $date = null,
        int $quantity = 5,
        string $unitPrice = '150.00'
    ): Sale {
        $sale = $this->seedSale($investment, $user, $customer, $date, $quantity, $unitPrice);

        $this->runAsTenant(
            $investment->getTenant()->getId(),
            function () use ($sale, $user, $reason): void {
                static::getContainer()->get(SaleVoidService::class)->void($sale, $reason, $user);
                $this->em->flush();
            }
        );

        return $sale;
    }

    /**
     * Pago aplicado a una venta (para el detalle de venta). Se construye
     * la entidad directamente: Payment no tiene asserts de ciclo de vida.
     */
    protected function seedPayment(
        Sale $sale,
        string $amount = '500.00',
        string $method = Payment::METHOD_CASH,
        ?Customer $customer = null,
        ?string $reference = null
    ): Payment {
        $payment = new Payment();
        $payment->setTenant($sale->getTenant());
        $payment->setSale($sale);
        $payment->setCustomer($customer);
        $payment->setAmount($amount);
        $payment->setCurrency($sale->getCurrency());
        $payment->setPaymentDate(new \DateTimeImmutable('today'));
        $payment->setMethod($method);
        $payment->setReference($reference);

        $this->em->persist($payment);
        $this->em->flush();

        return $payment;
    }

    /**
     * Venta de OTRO negocio (tenant + usuario + inversión confirmada),
     * para verificar que ninguna vista muestra datos ajenos.
     */
    protected function seedForeignSale(): Sale
    {
        $tenant = new Tenant();
        $tenant->setName('Ajeno');
        $tenant->setSlug('ajeno');

        $user = new AppUser();
        $user->setEmail('admin@ajeno.local');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPasswordHash('x');
        $user->addMembership(new TenantMembership($user, $tenant));

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->flush();

        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock(
            $user,
            new \DateTimeImmutable('2026-01-01'),
            'Inversión ajena'
        );

        return $this->seedSale(
            $investment,
            $user,
            $this->seedCustomer($investment->getTenant(), 'Cliente ajeno'),
            quantity: 1
        );
    }

    /**
     * Ejecuta $fn con el TenantContext apuntado a $tenantId y restaura el
     * valor previo al terminar (también si $fn lanza). Es lo que hace el
     * TenantFilterListener en web, reproducido para los seeds.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function runAsTenant(int $tenantId, callable $fn): mixed
    {
        $context = static::getContainer()->get(TenantContext::class);
        $previous = $context->getTenantId();
        $context->setTenantId($tenantId);

        try {
            return $fn();
        } finally {
            $context->setTenantId($previous);
        }
    }
}
