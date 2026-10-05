<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvestmentItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentItem::class);
    }

    /**
     * @return InvestmentItem[]
     */
    public function findByInvestment(int $investmentId): array
    {
        return $this->createQueryBuilder('ii')
            ->andWhere('ii.investment = :investmentId')
            ->setParameter('investmentId', $investmentId)
            ->orderBy('ii.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return InvestmentItem[]
     * Returns items that have remaining stock (SUM(quantity_delta) > 0)
     */
    public function findWithRemainingStock(int $tenantId): array
    {
        return $this->createQueryBuilder('ii')
            ->innerJoin('ii.inventoryMovements', 'im')
            ->andWhere('ii.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->groupBy('ii.id')
            ->having('SUM(im.quantityDelta) > 0')
            ->orderBy('ii.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}