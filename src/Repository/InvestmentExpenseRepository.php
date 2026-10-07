<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvestmentExpense;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentExpense::class);
    }

    public function sumExpensesByInvestment(int $investmentId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.amount) AS total')
            ->andWhere('e.investment = :investmentId')
            ->andWhere('e.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function sumExpensesUsdByInvestment(int $investmentId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.amountUsd) AS total')
            ->andWhere('e.investment = :investmentId')
            ->andWhere('e.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.0000');
    }

    /**
     * @return InvestmentExpense[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.investment = :investmentId')
            ->andWhere('e.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('e.expenseDate', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return InvestmentExpense[]
     */
    public function findAllocatedByInvestment(int $investmentId, int $tenantId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.investment = :investmentId')
            ->andWhere('e.tenant = :tenantId')
            ->andWhere('e.isAllocated = :allocated')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('allocated', true)
            ->orderBy('e.expenseDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function sumAllocatedByInvestment(int $investmentId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.amount) AS total')
            ->andWhere('e.investment = :investmentId')
            ->andWhere('e.tenant = :tenantId')
            ->andWhere('e.isAllocated = :allocated')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('allocated', true)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function sumNonAllocatedByInvestment(int $investmentId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.amount) AS total')
            ->andWhere('e.investment = :investmentId')
            ->andWhere('e.tenant = :tenantId')
            ->andWhere('e.isAllocated = :allocated')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('allocated', false)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }
}
