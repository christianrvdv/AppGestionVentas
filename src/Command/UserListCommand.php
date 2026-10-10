<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AppUser;
use App\Entity\TenantMembership;
use App\Repository\Contract\AppUserRepositoryInterface;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Repository\Contract\TenantRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lista los usuarios del sistema con sus membresías (ADR-0016).
 *
 * Visión global del super_admin desde la CLI:
 *
 *     php bin/console app:user:list
 *     php bin/console app:user:list --tenant=demo
 *
 * Cada fila muestra email, roles globales (AppUser::roles + ROLE_USER
 * implícito), los negocios a los que pertenece con su rol de membresía,
 * y si la cuenta está activa. Con --tenant solo se listan los usuarios
 * de ese negocio (los que tienen membresía en él); los super_admin, al
 * no tener membresías, solo aparecen en la lista general.
 */
#[AsCommand(
    name: 'app:user:list',
    description: 'Lista los usuarios del sistema con sus roles globales y membresías por negocio.',
)]
final class UserListCommand extends Command
{
    public function __construct(
        private readonly AppUserRepositoryInterface $userRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantMembershipRepositoryInterface $membershipRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Slug del negocio: solo lista sus usuarios (los que tienen membresía en él)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantOption = $input->getOption('tenant');
        $tenant = null;

        if ($tenantOption !== null) {
            $tenant = $this->tenantRepository->findBySlug((string) $tenantOption);

            if ($tenant === null) {
                $io->error(sprintf('No existe ningún negocio con el slug "%s".', $tenantOption));

                return Command::FAILURE;
            }
        }

        // Con --tenant se parten de las membresías del negocio (con el
        // usuario hidratado); sin él, de todos los usuarios del sistema.
        if ($tenant !== null) {
            $memberships = $this->membershipRepository->findByTenant($tenant);

            $users = [];
            foreach ($memberships as $membership) {
                $users[] = $membership->getUser();
            }
        } else {
            $users = $this->userRepository->findAll();
        }

        if ($users === []) {
            $io->warning($tenant !== null
                ? sprintf('El negocio "%s" no tiene usuarios con membresía.', $tenant->getSlug())
                : 'No hay usuarios registrados.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($users as $user) {
            $rows[] = [
                $user->getEmail(),
                implode(', ', $user->getRoles()),
                $this->formatMemberships($user, $tenant),
                $user->isActive() ? 'sí' : 'no',
            ];
        }

        $io->table(
            ['Email', 'Roles', 'Negocios (rol de membresía)', 'Activo'],
            $rows
        );

        $io->text(sprintf('%d usuario(s).', \count($users)));

        return Command::SUCCESS;
    }

    /**
     * Negocio(s) del usuario con su rol de membresía. Si se filtró por
     * --tenant, solo esa membresía (la consulta ya partió de ella); sin
     * filtro, todas las membresías del usuario.
     */
    private function formatMemberships(AppUser $user, ?\App\Entity\Tenant $filterTenant): string
    {
        if ($filterTenant !== null) {
            foreach ($this->membershipRepository->findByUser($user) as $membership) {
                if ($membership->getTenant()->getId() === $filterTenant->getId()) {
                    return sprintf('%s (%s)', $membership->getTenant()->getSlug(), $membership->getRole());
                }
            }

            return '—';
        }

        $memberships = $this->membershipRepository->findByUser($user);

        if ($memberships === []) {
            return '—';
        }

        return implode(', ', array_map(
            static fn (TenantMembership $m): string => sprintf('%s (%s)', $m->getTenant()->getSlug(), $m->getRole()),
            $memberships
        ));
    }
}
