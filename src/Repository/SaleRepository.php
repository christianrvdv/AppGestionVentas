<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sale::class);
    }

    /**
     * @return Sale[]
     */
    public function findByDateRange(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.saleDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function sumSalesByPeriod(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $result = $this->createQueryBuilder('s')
            ->select('SUM(s.totalAmount) as total')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['total'] ?? '0.00';
    }

    public function sumProfitByPeriod(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $result = $this->createQueryBuilder('s')
            ->select('SUM(sl.profitLine) as total')
            ->innerJoin('s.lines', 'sl')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['total'] ?? '0.00';
    }

    /**
     * @return array<int, array{date: string, total: string, profit: string}>
     */
    public function dailyTotals(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $results = $this->createQueryBuilder('s')
            ->select(
                's.saleDate as date',
                'SUM(s.totalAmount) as total',
                '(
                    SELECT SUM(sl2.profitLine)
                    FROM App\Entity\SaleLine sl2
                    WHERE sl2.sale = s.id
                ) as profit'
            )
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('s.saleDate')
            ->orderBy('s.saleDate', 'ASC')
            ->getQuery()
            ->getResult();

        $formatted = [];
        foreach ($results as $row) {
            $formatted[] = [
                'date' => $row['date'] instanceof \DateTimeInterface
                    ? $row['date']->format('Y-m-d')
                    : $row['date'],
                'total' => $row['total'] ?? '0.00',
                'profit' => $row['profit'] ?? '0.00',
            ];
        }
        return $formatted;
    }
}