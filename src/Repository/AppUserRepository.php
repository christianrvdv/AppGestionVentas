<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppUser;
use App\Repository\Contract\AppUserRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<AppUser>
 */
#[AsAlias(AppUserRepositoryInterface::class)]
class AppUserRepository extends ServiceEntityRepository implements AppUserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppUser::class);
    }

    public function findOneByEmail(string $email): ?AppUser
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ÚNICO lookup sin filtro de tenant de todo el repositorio
     * (ADR-0015; ver AppUserRepositoryInterface).
     *
     * NO filtra por tenant a propósito: lo usa únicamente
     * AppUserProvider::refreshUser(), que recarga el usuario de la
     * sesión por id. Nunca se usa para leer datos de negocio: el
     * aislamiento entre tenants lo aportan el TenantFilter (pobulado
     * desde la sesión) y RequireTenantListener.
     *
     * Si aparece un caso "parecido", es un bug de diseño — abrir un ADR
     * antes de tocar esta firma. Ver AppUserRepositoryInterfaceTest.
     */
    public function findById(int $id): ?AppUser
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todos los usuarios del sistema (ADR-0016): visión global del
     * super_admin vía app:user:list. Lista identidades, no datos de
     * negocio; el filtro de tenant no aplica a app_user (no tiene
     * asociación `tenant`).
     *
     * @return AppUser[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
