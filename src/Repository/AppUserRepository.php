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

    public function findByIdAndTenant(int $id, int $tenantId): ?AppUser
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.id = :id')
            ->andWhere('u.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ÚNICO lookup sin filtro de tenant de todo el repositorio.
     *
     * NO filtra por tenant a propósito: lo usa únicamente
     * AppUserProvider::refreshUser(), que recarga el usuario de la
     * sesión por id con el TenantFilter apagado y después COMPARA el
     * tenant persistido con el que declara la sesión, rechazando con
     * UnsupportedUserException si difieren. Nunca devuelve datos de
     * otro tenant: el mismatch muere antes de usarse.
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

    public function findOneByEmailAndTenant(string $email, int $tenantId): ?AppUser
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->andWhere('u.tenant = :tenantId')
            ->setParameter('email', $email)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return AppUser[]
     */
    public function findActiveByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.tenant = :tenantId')
            ->andWhere('u.isActive = :active')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('active', true)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
