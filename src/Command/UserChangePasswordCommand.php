<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AppUser;
use App\Repository\Contract\AppUserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cambia la contraseña de un usuario desde la CLI (ADR-0016).
 *
 * Existe para resetear contraseñas SIN UI (la recuperación por email
 * queda para una fase posterior): el administrador del sistema ejecuta
 *
 *     php bin/console app:user:change-password --email=...
 *
 * y teclea la nueva contraseña de forma oculta con confirmación. La
 * contraseña se hashea con el mismo hasher que usa el login (auto en
 * producción, cost mínimo en test) y se guarda con un solo flush.
 */
#[AsCommand(
    name: 'app:user:change-password',
    description: 'Cambia la contraseña de un usuario existente (reset sin UI).',
)]
final class UserChangePasswordCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly AppUserRepositoryInterface $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email del usuario cuya contraseña cambia')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Nueva contraseña en claro (8+ caracteres). Si se omite, se pregunta de forma oculta con confirmación');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = trim((string) ($input->getOption('email') ?? ''));

        if ($email === '') {
            $io->error('La opción --email es obligatoria.');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneByEmail($email);

        if (!$user instanceof AppUser) {
            $io->error(sprintf('No existe ningún usuario con el email "%s".', $email));

            return Command::FAILURE;
        }

        $password = $input->getOption('password');

        if ($password !== null) {
            $password = (string) $password;

            if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $io->error(sprintf('La contraseña debe tener al menos %d caracteres.', self::MIN_PASSWORD_LENGTH));

                return Command::FAILURE;
            }
        } else {
            if (!$input->isInteractive()) {
                $io->error('La opción --password es obligatoria en modo no interactivo.');

                return Command::FAILURE;
            }

            $password = (string) $io->askHidden('Nueva contraseña (8+ caracteres)', static function (?string $value) use ($io): string {
                $value = (string) $value;

                if (mb_strlen($value) < self::MIN_PASSWORD_LENGTH) {
                    $io->error(sprintf('La contraseña debe tener al menos %d caracteres.', self::MIN_PASSWORD_LENGTH));

                    throw new \InvalidArgumentException('Contraseña corta.');
                }

                return $value;
            });

            $confirmation = (string) $io->askHidden('Repite la nueva contraseña');

            if ($password !== $confirmation) {
                $io->error('Las contraseñas no coinciden.');

                return Command::FAILURE;
            }
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        $this->em->flush();

        $io->success(sprintf('Contraseña actualizada para "%s".', $email));

        return Command::SUCCESS;
    }
}
