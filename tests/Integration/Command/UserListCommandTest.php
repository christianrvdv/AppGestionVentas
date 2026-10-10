<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * app:user:list: visión global de usuarios (ADR-0016).
 *
 * La tabla pinta email, roles globales (incluido ROLE_USER que añade
 * getRoles()), negocios con su rol de membresía, y si la cuenta está
 * activa. Con --tenant solo los miembros de ese negocio.
 */
final class UserListCommandTest extends IntegrationTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = static::getContainer()->get('kernel');
        \assert($kernel instanceof KernelInterface);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $command = $application->find('app:user:list');
        $this->tester = new CommandTester($command);
    }

    public function testListsUsersWithTheirMemberships(): void
    {
        $tenant = $this->createTenant('acme');
        $this->createAppUser($tenant, 'admin@acme.local');

        $status = $this->tester->execute([]);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('admin@acme.local', $display);
        $this->assertStringContainsString('ROLE_ADMIN', $display);
        $this->assertStringContainsString('acme (ROLE_ADMIN)', $display, 'Debe pintar negocio y rol de membresía.');
        $this->assertStringContainsString('1 usuario(s).', $display);
    }

    public function testListsSuperAdminWithoutMemberships(): void
    {
        $superAdmin = new AppUser();
        $superAdmin->setEmail('super@example.com');
        $superAdmin->setPasswordHash('hash-test');
        $superAdmin->setRoles([AppUser::ROLE_SUPER_ADMIN]);
        $this->em->persist($superAdmin);
        $this->em->flush();

        $status = $this->tester->execute([]);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('super@example.com', $display);
        $this->assertStringContainsString('ROLE_SUPER_ADMIN', $display);
        $this->assertMatchesRegularExpression('/super@example\.com\s+ROLE_SUPER_ADMIN, ROLE_USER\s+—\s+sí/', $display);
    }

    public function testTenantOptionFiltersToMembersOfThatBusiness(): void
    {
        $acme = $this->createTenant('acme');
        $this->createAppUser($acme, 'de-acme@acme.local');

        $otro = new Tenant();
        $otro->setName('Otro');
        $otro->setSlug('otro');
        $this->em->persist($otro);

        $ajeno = new AppUser();
        $ajeno->setEmail('de-otro@otro.local');
        $ajeno->setPasswordHash('hash-test');
        $this->em->persist($ajeno);

        $membership = new TenantMembership($ajeno, $otro);
        $membership->setRole(TenantMembership::ROLE_USER);
        $ajeno->addMembership($membership);
        $this->em->persist($membership);
        $this->em->flush();

        $status = $this->tester->execute(['--tenant' => 'acme']);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('de-acme@acme.local', $display);
        $this->assertStringNotContainsString('de-otro@otro.local', $display, 'Los miembros de otros negocios no deben aparecer.');
        $this->assertStringContainsString('1 usuario(s).', $display);
    }

    public function testRejectsUnknownTenant(): void
    {
        $status = $this->tester->execute(['--tenant' => 'no-existe']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('No existe ningún negocio', $this->tester->getDisplay());
    }

    public function testWarnsWhenThereAreNoUsers(): void
    {
        $status = $this->tester->execute([]);

        $this->assertSame(0, $status, 'Sin usuarios no es un error: es un estado vacío.');
        $this->assertStringContainsString('No hay usuarios registrados', $this->tester->getDisplay());
    }
}
