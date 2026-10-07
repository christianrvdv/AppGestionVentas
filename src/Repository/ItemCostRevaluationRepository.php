<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ItemCostRevaluation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ItemCostRevaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ItemCostRevaluation::class);
    }

    /**
     * @return ItemCostRevaluation[]
     */
    public function findByItem(int $itemId, int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.investmentItem = :itemId')
            ->andWhere('r.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ItemCostRevaluation[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.investmentItem', 'ii')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('r.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLastByItem(int $itemId, int $tenantId): ?ItemCostRevaluation
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.investmentItem = :itemId')
            ->andWhere('r.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Suma de ganancia/pérdida latente por revaluación de una inversión.
     * Positivo = el costo subió (pérdida latente).
     * Negativo = el costo bajó (ganancia latente).
     */
    public function sumGainLossByInvestment(int $investmentId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('r')
            ->select('SUM(r.revaluationGainLoss) AS total')
            ->innerJoin('r.investmentItem', 'ii')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('r.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    /**
     * @return ItemCostRevaluation[]
     */
    public function findByDateRange(
        int $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->andWhere('r.createdAt >= :from')
            ->andWhere('r.createdAt <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
