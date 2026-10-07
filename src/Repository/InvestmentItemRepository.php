<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvestmentItem;
use App\Repository\Contract\InvestmentItemRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(InvestmentItemRepositoryInterface::class)]
class InvestmentItemRepository extends ServiceEntityRepository implements InvestmentItemRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentItem::class);
    }

    /**
     * @return InvestmentItem[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array
    {
        return $this->createQueryBuilder('ii')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('ii.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('ii.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?InvestmentItem
    {
        return $this->createQueryBuilder('ii')
            ->andWhere('ii.id = :id')
            ->andWhere('ii.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Ítems con stock remanente (SUM(quantity_delta) > 0).
     *
     * @return InvestmentItem[]
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

    /**
     * Ítems de una inversión con stock remanente.
     *
     * @return InvestmentItem[]
     */
    public function findWithRemainingStockByInvestment(int $investmentId, int $tenantId): array
    {
        return $this->createQueryBuilder('ii')
            ->innerJoin('ii.inventoryMovements', 'im')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('ii.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->groupBy('ii.id')
            ->having('SUM(im.quantityDelta) > 0')
            ->orderBy('ii.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ítems de un producto con stock remanente.
     *
     * @return InvestmentItem[]
     */
    public function findWithRemainingStockByProduct(int $productId, int $tenantId): array
    {
        return $this->createQueryBuilder('ii')
            ->innerJoin('ii.inventoryMovements', 'im')
            ->andWhere('ii.product = :productId')
            ->andWhere('ii.tenant = :tenantId')
            ->setParameter('productId', $productId)
            ->setParameter('tenantId', $tenantId)
            ->groupBy('ii.id')
            ->having('SUM(im.quantityDelta) > 0')
            ->orderBy('ii.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $ids
     *
     * @return InvestmentItem[]
     */
    public function findByIdsAndTenant(array $ids, int $tenantId): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('ii')
            ->andWhere('ii.id IN (:ids)')
            ->andWhere('ii.tenant = :tenantId')
            ->setParameter('ids', $ids)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();
    }

    public function countByInvestment(int $investmentId, int $tenantId): int
    {
        return (int) $this->createQueryBuilder('ii')
            ->select('COUNT(ii.id)')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('ii.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
