<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Investment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Investment::class);
    }

    /**
     * @return Investment[]
     */
    public function findByDateRange(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.investmentDate >= :from')
            ->andWhere('i.investmentDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.investmentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Investment[]
     */
    public function findByStatus(int $tenantId, string $status): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.status = :status')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('status', $status)
            ->orderBy('i.investmentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function sumInvestmentsByPeriod(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.totalInvestment) as total')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.investmentDate >= :from')
            ->andWhere('i.investmentDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['total'] ?? '0.00';
    }
}