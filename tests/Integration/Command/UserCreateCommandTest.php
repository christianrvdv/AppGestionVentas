<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\AppUser;
use App\Entity\TenantMembership;
use App\Repository\Contract\AppUserRepositoryInterface;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * app:user:create: el ÚNICO mecanismo de creación de usuarios (ADR-0016).
 *
 * Sobre BD real de test con transacción de DAMA, igual que el resto de
 * la suite de integración. Los exit codes y mensajes son parte del
 * contrato CLI: un script de operaciones los interpreta.
 */
final class UserCreateCommandTest extends IntegrationTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = static::getContainer()->get('kernel');
        \assert($kernel instanceof KernelInterface);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $command = $application->find('app:user:create');
        $this->tester = new CommandTester($command);
    }

    public function testCreatesSuperAdminWithoutMemberships(): void
    {
        $status = $this->tester->execute([
            '--email' => 'super@example.com',
            '--password' => 'secret123',
            '--super-admin' => true,
        ]);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $user = $this->repository()->findOneByEmail('super@example.com');
        $this->assertNotNull($user, 'El super_admin debe quedar persistido.');
        $this->assertSame([AppUser::ROLE_SUPER_ADMIN, 'ROLE_USER'], $user->getRoles());
        $this->assertTrue($user->isSuperAdmin());

        $memberships = static::getContainer()->get(TenantMembershipRepositoryInterface::class)
            ->findByUser($user);
        $this->assertSame([], $memberships, 'Un super_admin no tiene membresías (ADR-0016).');
    }

    public function testCreatesPlainUserWithTenantMembership(): void
    {
        $tenant = $this->createTenant('acme');

        $status = $this->tester->execute([
            '--email' => 'operario@example.com',
            '--password' => 'secret123',
            '--tenant' => 'acme',
            '--role' => TenantMembership::ROLE_ADMIN,
        ]);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $user = $this->repository()->findOneByEmail('operario@example.com');
        $this->assertNotNull($user);
        $this->assertFalse($user->isSuperAdmin());

        $memberships = static::getContainer()->get(TenantMembershipRepositoryInterface::class)
            ->findByUser($user);
        $this->assertCount(1, $memberships);
        $this->assertSame($tenant->getId(), $memberships[0]->getTenant()->getId());
        $this->assertSame(TenantMembership::ROLE_ADMIN, $memberships[0]->getRole());
        $this->assertTrue($memberships[0]->isDefault(), 'La única membresía debe ser la por defecto.');
    }

    public function testCreatesUserWithoutMembershipsByDefault(): void
    {
        $status = $this->tester->execute([
            '--email' => 'nuevo@example.com',
            '--password' => 'secret123',
        ]);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $user = $this->repository()->findOneByEmail('nuevo@example.com');
        $this->assertNotNull($user);
        // getRoles() añade ROLE_USER por convención: sin opciones, el
        // usuario queda sin roles propios.
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertFalse($user->isSuperAdmin());

        $memberships = static::getContainer()->get(TenantMembershipRepositoryInterface::class)
            ->findByUser($user);
        $this->assertSame([], $memberships, 'Sin --tenant no debe crearse membresía.');
    }

    public function testHashesThePasswordWithTheApplicationHasher(): void
    {
        $this->tester->execute([
            '--email' => 'hash@example.com',
            '--password' => 'secret123',
        ]);

        $user = $this->repository()->findOneByEmail('hash@example.com');
        $this->assertNotNull($user);
        $this->assertNotSame('secret123', $user->getPassword(), 'La contraseña jamás se guarda en claro.');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, 'secret123'));
        $this->assertFalse($hasher->isPasswordValid($user, 'otra-contraseña'));
    }

    public function testRejectsDuplicateEmail(): void
    {
        $tenant = $this->createTenant('acme');
        $this->createAppUser($tenant, 'duplicado@example.com');

        $status = $this->tester->execute([
            '--email' => 'duplicado@example.com',
            '--password' => 'secret123',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Ya existe un usuario', $this->tester->getDisplay());
    }

    public function testRejectsShortPassword(): void
    {
        $status = $this->tester->execute([
            '--email' => 'corto@example.com',
            '--password' => 'short',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('al menos 8 caracteres', $this->tester->getDisplay());
        $this->assertNull($this->repository()->findOneByEmail('corto@example.com'), 'No debe persistirse nada.');
    }

    public function testRejectsRoleWithoutTenant(): void
    {
        $status = $this->tester->execute([
            '--email' => 'rol@example.com',
            '--password' => 'secret123',
            '--role' => TenantMembership::ROLE_ADMIN,
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('--role solo aplica junto a --tenant', $this->tester->getDisplay());
    }

    public function testRejectsUnknownTenant(): void
    {
        $status = $this->tester->execute([
            '--email' => 'sin-tenant@example.com',
            '--password' => 'secret123',
            '--tenant' => 'no-existe',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('No existe ningún negocio', $this->tester->getDisplay());
    }

    public function testRejectsInvalidMembershipRole(): void
    {
        $this->createTenant('acme');

        $status = $this->tester->execute([
            '--email' => 'rol-malo@example.com',
            '--password' => 'secret123',
            '--tenant' => 'acme',
            '--role' => 'ROLE_NO_EXISTE',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Rol de membresía inválido', $this->tester->getDisplay());
    }

    public function testRequiresEmailInNonInteractiveMode(): void
    {
        $status = $this->tester->execute(['--password' => 'secret123'], ['interactive' => false]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('--email es obligatoria', $this->tester->getDisplay());
    }

    public function testInteractiveFlowAsksEmailAndPasswordWithConfirmation(): void
    {
        $this->tester->setInputs(['interactivo@example.com', 'secret123', 'secret123']);
        $status = $this->tester->execute([], ['interactive' => true]);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $user = $this->repository()->findOneByEmail('interactivo@example.com');
        $this->assertNotNull($user, 'El usuario pedido en el diálogo debe quedar creado.');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($user, 'secret123'));
    }

    public function testInteractiveFlowRejectsMismatchedPasswordConfirmation(): void
    {
        $this->tester->setInputs(['conflicto@example.com', 'secret123', 'diferente1']);
        $status = $this->tester->execute([], ['interactive' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('no coinciden', $this->tester->getDisplay());
        $this->assertNull($this->repository()->findOneByEmail('conflicto@example.com'));
    }

    private function repository(): AppUserRepositoryInterface
    {
        return static::getContainer()->get(AppUserRepositoryInterface::class);
    }
}
