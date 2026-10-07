<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UsdRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UsdRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsdRate::class);
    }

    /**
     * Tasa vigente del tenant. Determinista: ante correcciones en la misma
     * fecha, devuelve la última insertada (createdAt DESC, id DESC).
     */
    public function findCurrent(int $tenantId): ?UsdRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('r.rateDate', 'DESC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Alias semántico de findCurrent(). Se mantiene por compatibilidad
     * con consumidores previos.
     */
    public function findLatestForTenant(int $tenantId): ?UsdRate
    {
        return $this->findCurrent($tenantId);
    }

    /**
     * Tasa vigente antes (o en) una fecha dada, considerando correcciones.
     */
    public function findCurrentBefore(int $tenantId, \DateTimeImmutable $date): ?UsdRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->andWhere('r.rateDate <= :date')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('date', $date)
            ->orderBy('r.rateDate', 'DESC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestBefore(int $tenantId, \DateTimeImmutable $date): ?UsdRate
    {
        return $this->findCurrentBefore($tenantId, $date);
    }

    /**
     * Tasa efectiva de una fecha concreta. Si hay correcciones, devuelve
     * la última insertada. Devuelve null si no hay ninguna tasa para esa fecha.
     *
     * Reemplaza al anterior findByDate(), que podía lanzar
     * NonUniqueResultException cuando existían correcciones.
     */
    public function findEffectiveByDate(int $tenantId, \DateTimeImmutable $date): ?UsdRate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->andWhere('r.rateDate = :date')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('date', $date)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return UsdRate[]
     */
    public function findByDateRange(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenantId')
            ->andWhere('r.rateDate >= :from')
            ->andWhere('r.rateDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.rateDate', 'ASC')
            ->addOrderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
