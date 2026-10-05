<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvestmentSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentSummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentSummary::class);
    }

    public function findByInvestment(int $investmentId): ?InvestmentSummary
    {
        return $this->createQueryBuilder('is')
            ->andWhere('is.investment = :investmentId')
            ->setParameter('investmentId', $investmentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return InvestmentSummary[]
     */
    public function findTopRecovered(int $tenantId, int $limit = 10): array
    {
        return $this->createQueryBuilder('is')
            ->innerJoin('is.investment', 'i')
            ->andWhere('is.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('is.recoveryPct', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}