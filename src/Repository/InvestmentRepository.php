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

    public function findByIdAndTenant(int $id, int $tenantId): ?Investment
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.id = :id')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
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

    /**
     * Suma de inversiones del período. Excluye CANCELLED: una inversión
     * cancelada nunca se ejecutó, no debe contarse como capital invertido.
     */
    public function sumInvestmentsByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): string
    {
        $result = $this->createQueryBuilder('i')
            ->select('SUM(i.totalInvestment) AS total')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.investmentDate >= :from')
            ->andWhere('i.investmentDate <= :to')
            ->andWhere('i.status != :cancelled')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('cancelled', Investment::STATUS_CANCELLED)
            ->getQuery()
            ->getOneOrNullResult();

        return (string)($result['total'] ?? '0.00');
    }

    /**
     * Inversiones candidatas a revaluación: abiertas/parciales, no canceladas
     * y con tasa USD definida.
     *
     * @return Investment[]
     */
    public function findRevaluable(int $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.status IN (:statuses)')
            ->andWhere('i.usdRateSnapshot IS NOT NULL')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('statuses', [Investment::STATUS_OPEN, Investment::STATUS_PARTIAL])
            ->orderBy('i.investmentDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Investment[]
     */
    public function findActiveByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.status IN (:statuses)')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('statuses', [Investment::STATUS_OPEN, Investment::STATUS_PARTIAL])
            ->orderBy('i.investmentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(int $tenantId, string $status): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.status = :status')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
