<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AppUser;
use App\Entity\TenantMembership;
use App\Repository\Contract\AppUserRepositoryInterface;
use App\Repository\Contract\TenantRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cuenta de usuarios desde la línea de comandos (ADR-0016).
 *
 * Es el ÚNICO mecanismo de creación de usuarios (no hay seed de admin
 * en fixtures ni en migraciones): el primer super_admin se crea con
 *
 *     php bin/console app:user:create --super-admin --email=... 
 *
 * y los usuarios normales con o sin tenant:
 *
 *     # sin membresía: al loguear irá a /onboarding
 *     php bin/console app:user:create --email=... --password=...
 *
 *     # con membresía: al loguear entra directo a ese negocio
 *     php bin/console app:user:create --email=... --password=... \
 *         --tenant=demo --role=ROLE_ADMIN
 *
 * El rol de membresía (--role) solo aplica CON --tenant: los roles de
 * negocio viven en TenantMembership, no en AppUser. Un super_admin no
 * tiene membresías ni tenant activo (ADR-0016), así que --tenant y
 * --role se ignoran.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Crea un usuario de la aplicación (normal, con tenant opcional, o super_admin global).',
)]
final class UserCreateCommand extends Command
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly AppUserRepositoryInterface $userRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email del usuario (único en todo el sistema)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Contraseña en claro (8+ caracteres). Si se omite, se pregunta de forma oculta con confirmación')
            ->addOption('super-admin', null, InputOption::VALUE_NONE, 'Crea un super_admin global (ROLE_SUPER_ADMIN, sin membresías)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Slug del negocio al que dar de alta con una membresía')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Rol de la membresía: ROLE_USER o ROLE_ADMIN (solo con --tenant; por defecto ROLE_USER)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $isSuperAdmin = (bool) $input->getOption('super-admin');

        $email = $this->resolveEmail($input, $io);
        if ($email === null) {
            return Command::FAILURE;
        }

        if ($this->userRepository->findOneByEmail($email) !== null) {
            $io->error(sprintf('Ya existe un usuario con el email "%s".', $email));

            return Command::FAILURE;
        }

        $password = $this->resolvePassword($input, $io);
        if ($password === null) {
            return Command::FAILURE;
        }

        $tenant = null;
        $membershipRole = null;

        if ($isSuperAdmin) {
            if ($input->getOption('tenant') !== null || $input->getOption('role') !== null) {
                $io->note('Un super_admin no tiene membresías: --tenant y --role se ignoran.');
            }
        } else {
            $tenantOption = $input->getOption('tenant');

            if ($tenantOption !== null) {
                $tenant = $this->tenantRepository->findBySlug((string) $tenantOption);

                if ($tenant === null) {
                    $io->error(sprintf('No existe ningún negocio con el slug "%s".', $tenantOption));

                    return Command::FAILURE;
                }

                $membershipRole = $this->resolveMembershipRole($input, $io);
                if ($membershipRole === false) {
                    return Command::FAILURE;
                }
            } elseif ($input->getOption('role') !== null) {
                $io->error('La opción --role solo aplica junto a --tenant (los roles de negocio viven en la membresía).');

                return Command::FAILURE;
            }
        }

        $user = new AppUser();
        $user->setEmail($email);
        $user->setRoles($isSuperAdmin ? [AppUser::ROLE_SUPER_ADMIN] : []);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);

        if ($tenant !== null) {
            $membership = new TenantMembership($user, $tenant);
            $membership->setRole($membershipRole);
            $membership->setIsDefault(true);
            $user->addMembership($membership);
            $this->em->persist($membership);
        }

        $this->em->flush();

        $io->success(sprintf('Usuario "%s" creado.', $email));

        if ($isSuperAdmin) {
            $io->text('Rol global: ROLE_SUPER_ADMIN (sin membresías ni tenant activo).');
        } elseif ($tenant !== null) {
            $io->text(sprintf(
                'Membresía creada en "%s" con rol %s (negocio por defecto).',
                $tenant->getSlug(),
                $membershipRole
            ));
        } else {
            $io->text('Sin membresías: al iniciar sesión irá a /onboarding para crear su primer negocio.');
        }

        return Command::SUCCESS;
    }

    private function resolveEmail(InputInterface $input, SymfonyStyle $io): ?string
    {
        $email = $input->getOption('email');

        if ($email === null) {
            if (!$input->isInteractive()) {
                $io->error('La opción --email es obligatoria en modo no interactivo.');

                return null;
            }

            $email = $io->ask('Email del usuario', null, static function (?string $value) use ($io): string {
                $trimmed = trim((string) $value);

                if ($trimmed === '' || filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
                    $io->error('El email no tiene un formato válido.');

                    throw new \InvalidArgumentException('Email inválido.');
                }

                return $trimmed;
            });
        }

        $email = trim((string) $email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('El email no tiene un formato válido.');

            return null;
        }

        return $email;
    }

    private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        $password = $input->getOption('password');

        if ($password !== null) {
            return $this->checkPasswordLength((string) $password, $io) ? (string) $password : null;
        }

        if (!$input->isInteractive()) {
            $io->error('La opción --password es obligatoria en modo no interactivo.');

            return null;
        }

        $password = (string) $io->askHidden('Contraseña (8+ caracteres)', static function (?string $value) use ($io): string {
            $value = (string) $value;

            if (mb_strlen($value) < self::MIN_PASSWORD_LENGTH) {
                $io->error(sprintf('La contraseña debe tener al menos %d caracteres.', self::MIN_PASSWORD_LENGTH));

                throw new \InvalidArgumentException('Contraseña corta.');
            }

            return $value;
        });

        $confirmation = (string) $io->askHidden('Repite la contraseña');

        if ($password !== $confirmation) {
            $io->error('Las contraseñas no coinciden.');

            return null;
        }

        return $password;
    }

    /**
     * @return string|false false = ya se reportó el error
     */
    private function resolveMembershipRole(InputInterface $input, SymfonyStyle $io): string|false
    {
        $role = $input->getOption('role');

        if ($role === null) {
            return TenantMembership::ROLE_USER;
        }

        $allowed = [TenantMembership::ROLE_USER, TenantMembership::ROLE_ADMIN];

        if (!\in_array($role, $allowed, true)) {
            $io->error(sprintf(
                'Rol de membresía inválido: "%s". Valores permitidos: %s.',
                $role,
                implode(', ', $allowed)
            ));

            return false;
        }

        return (string) $role;
    }

    private function checkPasswordLength(string $password, SymfonyStyle $io): bool
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('La contraseña debe tener al menos %d caracteres.', self::MIN_PASSWORD_LENGTH));

            return false;
        }

        return true;
    }
}
