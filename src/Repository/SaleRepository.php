<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sale;
use App\Repository\Contract\SaleRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Sale>
 */
#[AsAlias(SaleRepositoryInterface::class)]
class SaleRepository extends ServiceEntityRepository implements SaleRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sale::class);
    }

    public function createPaginatedQueryBuilder(
        int $tenantId,
        ?int $investmentId = null,
        ?int $customerId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        bool $includeVoided = false
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('s.saleDate', 'DESC')
            ->addOrderBy('s.id', 'DESC');

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        if ($investmentId !== null && $investmentId > 0) {
            $qb->andWhere('s.investment = :investmentId')
                ->setParameter('investmentId', $investmentId);
        }

        if ($customerId !== null && $customerId > 0) {
            $qb->andWhere('s.customer = :customerId')
                ->setParameter('customerId', $customerId);
        }

        // DATE_IMMUTABLE explícito: la columna sale_date es DATE, y sin el
        // tipo Doctrine enviaría 'Y-m-d H:i:s' (que PostgreSQL no acepta
        // comparado contra una columna date).
        if ($from !== null) {
            $qb->andWhere('s.saleDate >= :from')
                ->setParameter('from', $from, Types::DATE_IMMUTABLE);
        }

        if ($to !== null) {
            $qb->andWhere('s.saleDate <= :to')
                ->setParameter('to', $to, Types::DATE_IMMUTABLE);
        }

        return $qb;
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
     * Ganancia BRUTA por venta para un conjunto de ids.
     *
     * @param int[] $saleIds
     * @return array<int, string>
     */
    public function sumGrossProfitBySaleIds(array $saleIds, int $tenantId): array
    {
        if ([] === $saleIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('s.id AS saleId', 'SUM(sl.grossProfitLine) AS grossProfit')
            ->innerJoin('s.lines', 'sl')
            ->andWhere('s.id IN (:saleIds)')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('saleIds', array_values($saleIds))
            ->setParameter('tenantId', $tenantId)
            ->groupBy('s.id')
            ->getQuery()
            ->getArrayResult();

        $grossProfit = [];
        foreach ($rows as $row) {
            $grossProfit[(int) $row['saleId']] = (string) ($row['grossProfit'] ?? '0.00');
        }

        return $grossProfit;
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

    public function sumSalesByCustomer(
        int  $customerId,
        int  $tenantId,
        bool $includeVoided = false
    ): string
    {
        $qb = $this->createQueryBuilder('s')
            ->select('SUM(s.totalAmount) AS total')
            ->andWhere('s.customer = :customerId')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('customerId', $customerId)
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

    /**
     * Totales diarios con filtros opcionales por inversión y cliente.
     *
     * @return array<int, array{date: string, total: string, profit: string}>
     */
    public function dailyTotalsWithFilters(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int               $investmentId = null,
        ?int               $customerId = null,
        bool               $includeVoided = false
    ): array {
        $base = function () use ($tenantId, $from, $to, $investmentId, $customerId, $includeVoided) {
            $qb = $this->createQueryBuilder('s')
                ->andWhere('s.tenant = :tenantId')
                ->andWhere('s.saleDate >= :from')
                ->andWhere('s.saleDate <= :to')
                ->setParameter('tenantId', $tenantId)
                ->setParameter('from', $from)
                ->setParameter('to', $to);

            if ($investmentId !== null && $investmentId > 0) {
                $qb->andWhere('s.investment = :investmentId')
                    ->setParameter('investmentId', $investmentId);
            }

            if ($customerId !== null && $customerId > 0) {
                $qb->andWhere('s.customer = :customerId')
                    ->setParameter('customerId', $customerId);
            }

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

    /**
     * Total de ventas del sistema (panel admin, Fase 6.1). CONTEO CRUDO
     * de filas: incluye las anuladas (histórico completo, como en los
     * listados); el monto agregado que sí mide negocio real es
     * sumTotalByTenant(), que las excluye.
     *
     * Excepción documentada al invariante "toda consulta filtra por
     * tenant_id": es un conteo de filas y su único consumidor es /admin,
     * blindado con ROLE_SUPER_ADMIN (global por diseño, con el
     * TenantFilter ya deshabilitado por TenantFilterListener).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ventas de una tanda de tenants con EL TENANT EXPLÍCITO en la
     * cláusula: los contadores por fila de /admin/tenants y las métricas
     * de /admin/tenants/{id} (Fase 6.1). El SQLFilter está deshabilitado
     * para el super_admin, así que el aislamiento lo aporta este
     * parámetro, igual que en createPaginatedQueryBuilder().
     *
     * @param list<int> $tenantIds
     *
     * @return array<int, int> tenantId => cantidad (solo los pedidos)
     */
    public function countByTenants(array $tenantIds): array
    {
        if ([] === $tenantIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.tenant) AS tenantId', 'COUNT(s.id) AS total')
            ->andWhere('s.tenant IN (:tenantIds)')
            ->setParameter('tenantIds', $tenantIds)
            ->groupBy('s.tenant')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['tenantId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Monto total vendido de UN tenant en todo el histórico (métrica de
     * detalle en /admin/tenants/{id}). Excluye anuladas, como countAll.
     *
     * @return string suma con el formato decimal de la BD (para
     *               formatear con number_format en la vista)
     */
    public function sumTotalByTenant(int $tenantId): string
    {
        return (string) $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.totalAmount), 0)')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->andWhere('s.voidedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
