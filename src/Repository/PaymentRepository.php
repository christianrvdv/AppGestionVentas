<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Repository\Contract\PaymentRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Payment>
 */
#[AsAlias(PaymentRepositoryInterface::class)]
class PaymentRepository extends ServiceEntityRepository implements PaymentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?Payment
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function createPaginatedQueryBuilder(
        int                 $tenantId,
        ?int                $customerId = null,
        ?int                $saleId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?string             $method = null
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('p.paymentDate', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        if ($customerId !== null && $customerId > 0) {
            $qb->andWhere('p.customer = :customerId')
                ->setParameter('customerId', $customerId);
        }

        if ($saleId !== null && $saleId > 0) {
            $qb->andWhere('p.sale = :saleId')
                ->setParameter('saleId', $saleId);
        }

        // DATE_IMMUTABLE explícito: la columna payment_date es DATE, y sin
        // el tipo Doctrine enviaría 'Y-m-d H:i:s' (que PostgreSQL no
        // acepta comparado contra una columna date).
        if ($from !== null) {
            $qb->andWhere('p.paymentDate >= :from')
                ->setParameter('from', $from, Types::DATE_IMMUTABLE);
        }

        if ($to !== null) {
            $qb->andWhere('p.paymentDate <= :to')
                ->setParameter('to', $to, Types::DATE_IMMUTABLE);
        }

        if ($method !== null && $method !== '') {
            $qb->andWhere('p.method = :method')
                ->setParameter('method', $method);
        }

        return $qb;
    }

    /**
     * @return Payment[]
     */
    public function findBySale(int $saleId, int $tenantId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.sale = :saleId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('saleId', $saleId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('p.paymentDate', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Payment[]
     */
    public function findByCustomer(int $customerId, int $tenantId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.customer = :customerId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('customerId', $customerId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('p.paymentDate', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function sumBySale(int $saleId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.amount) AS total')
            ->andWhere('p.sale = :saleId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('saleId', $saleId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function sumByCustomer(int $customerId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.amount) AS total')
            ->andWhere('p.customer = :customerId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('customerId', $customerId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    /**
     * Pagos no aplicados a una venta (anticipos / a cuenta del cliente).
     *
     * @return Payment[]
     */
    public function findUnappliedByCustomer(int $customerId, int $tenantId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.customer = :customerId')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.sale IS NULL')
            ->setParameter('customerId', $customerId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('p.paymentDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function sumUnappliedByCustomer(int $customerId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.amount) AS total')
            ->andWhere('p.customer = :customerId')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.sale IS NULL')
            ->setParameter('customerId', $customerId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function sumByPeriod(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.amount) AS total')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.paymentDate >= :from')
            ->andWhere('p.paymentDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }
}
