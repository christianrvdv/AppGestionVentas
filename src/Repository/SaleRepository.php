<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sale;
use App\Repository\Contract\SaleRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SaleRepositoryInterface::class)]
class SaleRepository extends ServiceEntityRepository implements SaleRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sale::class);
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?Sale
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.id = :id')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param bool $includeVoided Si true, incluye ventas anuladas (auditoría).
     * @return Sale[]
     */
    public function findByDateRange(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.saleDate', 'DESC');

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function sumSalesByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('s')
            ->select('SUM(s.totalAmount) AS total')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        return (string)($result['total'] ?? '0.00');
    }

    /**
     * Ganancia BRUTA en el período (independiente del recoveryMode).
     * Por defecto excluye ventas anuladas.
     */
    public function sumProfitByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('s')
            ->select('SUM(sl.grossProfitLine) AS total')
            ->innerJoin('s.lines', 'sl')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        return (string)($result['total'] ?? '0.00');
    }

    /**
     * Ganancia RECONOCIDA en el período (según recoveryMode de cada inversión).
     * Por defecto excluye ventas anuladas.
     */
    public function sumRecognizedProfitByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('s')
            ->select('SUM(sl.recognizedProfitLine) AS total')
            ->innerJoin('s.lines', 'sl')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        return (string)($result['total'] ?? '0.00');
    }

    public function sumSalesByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('s')
            ->select('SUM(s.totalAmount) AS total')
            ->andWhere('s.investment = :investmentId')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        return (string)($result['total'] ?? '0.00');
    }

    /**
     * @return Sale[]
     */
    public function findByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.investment = :investmentId')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('s.saleDate', 'DESC');

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Sale[]
     */
    public function findByCustomer(
        int  $customerId,
        int  $tenantId,
        bool $includeVoided = false
    ): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.customer = :customerId')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('customerId', $customerId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('s.saleDate', 'DESC');

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function countByInvestment(int $investmentId, int $tenantId): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.investment = :investmentId')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Totales diarios (venta + ganancia bruta).
     *
     * Se hacen DOS consultas porque DQL no permite subqueries en SELECT.
     * Se mergean por fecha en PHP.
     *
     * Se usa el alias "dayKey" en lugar de "date" para evitar
     * colisiones con palabras reservadas en PostgreSQL/SQLite.
     *
     * @return array<int, array{date: string, total: string, profit: string}>
     */
    public function dailyTotals(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): array
    {
        $base = function () use ($tenantId, $from, $to, $includeVoided) {
            $qb = $this->createQueryBuilder('s')
                ->andWhere('s.tenant = :tenantId')
                ->andWhere('s.saleDate >= :from')
                ->andWhere('s.saleDate <= :to')
                ->setParameter('tenantId', $tenantId)
                ->setParameter('from', $from)
                ->setParameter('to', $to);

            if (!$includeVoided) {
                $qb->andWhere('s.voidedAt IS NULL');
            }
            return $qb;
        };

        $totals = $base()
            ->select('s.saleDate AS dayKey, SUM(s.totalAmount) AS total')
            ->groupBy('s.saleDate')
            ->orderBy('s.saleDate', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $profits = $base()
            ->select('s.saleDate AS dayKey, SUM(sl.grossProfitLine) AS profit')
            ->innerJoin('s.lines', 'sl')
            ->groupBy('s.saleDate')
            ->orderBy('s.saleDate', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $profitByDate = [];
        foreach ($profits as $row) {
            $profitByDate[$this->formatDateKey($row['dayKey'])] = (string)($row['profit'] ?? '0.00');
        }

        $formatted = [];
        foreach ($totals as $row) {
            $key = $this->formatDateKey($row['dayKey']);
            $formatted[] = [
                'date' => $key,
                'total' => (string)($row['total'] ?? '0.00'),
                'profit' => $profitByDate[$key] ?? '0.00',
            ];
        }

        return $formatted;
    }

    private function formatDateKey(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        return (string)$date;
    }
}
