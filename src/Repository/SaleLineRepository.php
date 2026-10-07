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
    public function findByInvestmentItem(
        int  $itemId,
        int  $tenantId,
        bool $includeVoided = false
    ): array
    {
        $qb = $this->createQueryBuilder('sl')
            ->innerJoin('sl.sale', 's')
            ->andWhere('sl.investmentItem = :itemId')
            ->andWhere('sl.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('sl.createdAt', 'ASC');

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return SaleLine[]
     */
    public function findBySale(int $saleId, int $tenantId): array
    {
        return $this->createQueryBuilder('sl')
            ->andWhere('sl.sale = :saleId')
            ->andWhere('sl.tenant = :tenantId')
            ->setParameter('saleId', $saleId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('sl.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return SaleLine[]
     */
    public function findByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): array
    {
        $qb = $this->createQueryBuilder('sl')
            ->innerJoin('sl.investmentItem', 'ii')
            ->innerJoin('sl.sale', 's')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('sl.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('sl.createdAt', 'ASC');

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function sumCostRecoveredByItem(
        int  $itemId,
        int  $tenantId,
        bool $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('sl')
            ->select('SUM(sl.costRecovered) AS total')
            ->innerJoin('sl.sale', 's')
            ->andWhere('sl.investmentItem = :itemId')
            ->andWhere('sl.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        return (string)($result['total'] ?? '0.00');
    }

    public function sumGrossProfitByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('sl')
            ->select('SUM(sl.grossProfitLine) AS total')
            ->innerJoin('sl.investmentItem', 'ii')
            ->innerJoin('sl.sale', 's')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('sl.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        return (string)($result['total'] ?? '0.00');
    }

    public function sumRecognizedProfitByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('sl')
            ->select('SUM(sl.recognizedProfitLine) AS total')
            ->innerJoin('sl.investmentItem', 'ii')
            ->innerJoin('sl.sale', 's')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('sl.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        return (string)($result['total'] ?? '0.00');
    }

    public function countUnitsSoldByItem(
        int  $itemId,
        int  $tenantId,
        bool $includeVoided = false
    ): int
    {
        $qb = $this->createQueryBuilder('sl')
            ->select('COALESCE(SUM(sl.quantity), 0)')
            ->innerJoin('sl.sale', 's')
            ->andWhere('sl.investmentItem = :itemId')
            ->andWhere('sl.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        return (int)$qb->getQuery()->getSingleScalarResult();
    }
}
