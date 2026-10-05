<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentExpenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, \App\Entity\InvestmentExpense::class);
    }

    public function sumExpensesByInvestment(int $investmentId): string
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.amount) as total')
            ->andWhere('e.investment = :investmentId')
            ->setParameter('investmentId', $investmentId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['total'] ?? '0.00';
    }
}