<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UsdRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UsdRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsdRate::class);
    }

    public function findLatestForTenant(int $tenantId): ?UsdRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('r.rateDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return UsdRate[]
     */
    public function findByDateRange(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->andWhere('r.rateDate >= :from')
            ->andWhere('r.rateDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.rateDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}