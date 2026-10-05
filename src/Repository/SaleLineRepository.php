<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SaleLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SaleLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaleLine::class);
    }

    /**
     * @return SaleLine[]
     */
    public function findByInvestmentItem(int $itemId): array
    {
        return $this->createQueryBuilder('sl')
            ->andWhere('sl.investmentItem = :itemId')
            ->setParameter('itemId', $itemId)
            ->orderBy('sl.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}