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
}
