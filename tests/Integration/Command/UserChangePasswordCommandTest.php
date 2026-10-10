<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\AppUser;
use App\Repository\Contract\AppUserRepositoryInterface;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * app:user:change-password: reset de contraseña sin UI (ADR-0016).
 *
 * El cambio se verifica con el hasher REAL de la aplicación: la
 * contraseña vieja deja de validar y la nueva pasa (los dos sentidos
 * del contrato, no solo "no lanzó excepción").
 */
final class UserChangePasswordCommandTest extends IntegrationTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = static::getContainer()->get('kernel');
        \assert($kernel instanceof KernelInterface);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $command = $application->find('app:user:change-password');
        $this->tester = new CommandTester($command);
    }

    public function testChangesPasswordOfAnExistingUser(): void
    {
        $tenant = $this->createTenant('acme');
        $user = $this->createAppUser($tenant, 'cambiar@example.com');
        $oldHash = $user->getPassword();

        $status = $this->tester->execute([
            '--email' => 'cambiar@example.com',
            '--password' => 'nueva-clave1',
        ]);

        $this->assertSame(0, $status, $this->tester->getDisplay());
        $this->assertStringContainsString('Contraseña actualizada', $this->tester->getDisplay());

        $reloaded = $this->repository()->findOneByEmail('cambiar@example.com');
        $this->assertNotNull($reloaded);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($reloaded, 'nueva-clave1'), 'La contraseña nueva debe validar.');
        $this->assertNotSame($oldHash, $reloaded->getPassword(), 'El hash debe haberse reemplazado.');
        $this->assertFalse($hasher->isPasswordValid($reloaded, 'hash-test'), 'La contraseña vieja debe dejar de validar.');
    }

    public function testRejectsUnknownEmail(): void
    {
        $status = $this->tester->execute([
            '--email' => 'fantasma@example.com',
            '--password' => 'nueva-clave1',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('No existe ningún usuario', $this->tester->getDisplay());
    }

    public function testRejectsShortPassword(): void
    {
        $tenant = $this->createTenant('acme');
        $this->createAppUser($tenant, 'corto@example.com');

        $status = $this->tester->execute([
            '--email' => 'corto@example.com',
            '--password' => 'short',
        ]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('al menos 8 caracteres', $this->tester->getDisplay());

        $reloaded = $this->repository()->findOneByEmail('corto@example.com');
        $this->assertNotNull($reloaded);
        $this->assertSame('hash-test', $reloaded->getPassword(), 'Con contraseña inválida no debe tocarse el hash.');
    }

    public function testRequiresEmailInNonInteractiveMode(): void
    {
        $status = $this->tester->execute(['--password' => 'nueva-clave1'], ['interactive' => false]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('--email es obligatoria', $this->tester->getDisplay());
    }

    public function testInteractiveFlowAsksTheNewPasswordWithConfirmation(): void
    {
        $tenant = $this->createTenant('acme');
        $this->createAppUser($tenant, 'dialogo@example.com');

        // El email siempre va por opción (contrato CLI: scriptable); lo
        // que se dialoga es la contraseña, oculta y con confirmación.
        $this->tester->setInputs(['clave-dialogo1', 'clave-dialogo1']);
        $status = $this->tester->execute(['--email' => 'dialogo@example.com'], ['interactive' => true]);

        $this->assertSame(0, $status, $this->tester->getDisplay());

        $reloaded = $this->repository()->findOneByEmail('dialogo@example.com');
        $this->assertNotNull($reloaded);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($reloaded, 'clave-dialogo1'));
    }

    public function testInteractiveFlowRejectsMismatchedConfirmation(): void
    {
        $tenant = $this->createTenant('acme');
        $this->createAppUser($tenant, 'discordante@example.com');

        $this->tester->setInputs(['clave-dialogo1', 'otra-diferente']);
        $status = $this->tester->execute(['--email' => 'discordante@example.com'], ['interactive' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('no coinciden', $this->tester->getDisplay());

        $reloaded = $this->repository()->findOneByEmail('discordante@example.com');
        $this->assertNotNull($reloaded);
        $this->assertSame('hash-test', $reloaded->getPassword(), 'Con confirmación discordante no debe cambiarse nada.');
    }

    private function repository(): AppUserRepositoryInterface
    {
        return static::getContainer()->get(AppUserRepositoryInterface::class);
    }
}
