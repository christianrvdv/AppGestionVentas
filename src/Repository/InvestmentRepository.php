<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Investment;
use App\Repository\Contract\InvestmentRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Investment>
 */
#[AsAlias(InvestmentRepositoryInterface::class)]
class InvestmentRepository extends ServiceEntityRepository implements InvestmentRepositoryInterface
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

    /**
     * Todas las inversiones del tenant, sin filtrar por estado (el
     * desplegable de filtros del listado de ventas debe ofrecer también
     * las inversiones recuperadas o cerradas, que son las que tienen
     * ventas con más historia).
     *
     * @return Investment[]
     */
    public function findAllByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('i.investmentDate', 'DESC')
            ->addOrderBy('i.code', 'DESC')
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

    public function countByCodePrefix(int $tenantId, string $prefix): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.tenant = :tenantId')
            ->andWhere('i.code LIKE :prefix')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('prefix', $prefix . '%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function createPaginatedQueryBuilder(int $tenantId, ?string $status): QueryBuilder
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('i.investmentDate', 'DESC')
            ->addOrderBy('i.code', 'DESC');

        if (null !== $status && '' !== $status) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', $status);
        }

        return $qb;
    }

    /**
     * Total de inversiones del sistema (panel admin, Fase 6.1).
     *
     * Excepcion documentada al invariante "toda consulta filtra por
     * tenant_id": es un CONTEO de filas, no datos de negocio, y su unico
     * consumidor es /admin, blindado con ROLE_SUPER_ADMIN (global por
     * diseno, sin TenantFilter). Incluye canceladas (historico).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Inversiones de una tanda de tenants con EL TENANT EXPLICITO en la
     * clausula: los contadores por fila de /admin/tenants y las metricas
     * de /admin/tenants/{id} (Fase 6.1). El SQLFilter esta deshabilitado
     * para el super_admin, asi que el aislamiento lo aporta este
     * parametro, igual que en createPaginatedQueryBuilder().
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

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.tenant) AS tenantId', 'COUNT(i.id) AS total')
            ->andWhere('i.tenant IN (:tenantIds)')
            ->setParameter('tenantIds', $tenantIds)
            ->groupBy('i.tenant')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['tenantId']] = (int) $row['total'];
        }

        return $counts;
    }
}
