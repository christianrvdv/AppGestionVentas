<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppUser::class);
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
