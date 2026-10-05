<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExpenseAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ExpenseAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseAllocation::class);
    }

    /**
     * @return ExpenseAllocation[]
     */
    public function findByInvestmentItem(int $itemId): array
    {
        return $this->createQueryBuilder('ea')
            ->andWhere('ea.investmentItem = :itemId')
            ->setParameter('itemId', $itemId)
            ->getQuery()
            ->getResult();
    }
}