<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentAllocation;
use App\Repository\Contract\PaymentAllocationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<PaymentAllocation>
 *
 * Todas las consultas se resuelven contra PaymentAllocation y filtran por
 * tenant a través del join con Payment (payment.tenant): la tabla puente
 * no guarda tenant y duplicarla sería una segunda fuente de verdad que
 * podría desincronizarse (ADR-0018).
 */
#[AsAlias(PaymentAllocationRepositoryInterface::class)]
class PaymentAllocationRepository extends ServiceEntityRepository implements PaymentAllocationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentAllocation::class);
    }

    public function findBySale(int $saleId, int $tenantId): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.payment', 'p')
            ->andWhere('a.sale = :saleId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('saleId', $saleId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('p.paymentDate', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByPayment(int $paymentId, int $tenantId): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.payment', 'p')
            ->andWhere('a.payment = :paymentId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('paymentId', $paymentId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function sumByPayment(int $paymentId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('a')
            ->select('SUM(a.amount) AS total')
            ->join('a.payment', 'p')
            ->andWhere('a.payment = :paymentId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('paymentId', $paymentId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function hasAllocationForSale(int $paymentId, int $saleId, int $tenantId): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->join('a.payment', 'p')
            ->andWhere('a.payment = :paymentId')
            ->andWhere('a.sale = :saleId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('paymentId', $paymentId)
            ->setParameter('saleId', $saleId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function sumAllocatedByPaymentIds(array $paymentIds, int $tenantId): array
    {
        if ($paymentIds === []) {
            return [];
        }

        $totals = array_fill_keys($paymentIds, '0.00');

        $allocations = $this->createQueryBuilder('a')
            ->join('a.payment', 'p')
            ->andWhere('a.payment IN (:paymentIds)')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('paymentIds', $paymentIds)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();

        foreach ($allocations as $allocation) {
            $paymentId = $allocation->getPayment()->getId();
            $totals[$paymentId] = bcadd($totals[$paymentId] ?? '0.00', $allocation->getAmount(), 2);
        }

        return $totals;
    }

    public function sumAllocatedByCustomer(int $customerId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('a')
            ->select('SUM(a.amount) AS total')
            ->join('a.payment', 'p')
            ->andWhere('p.customer = :customerId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('customerId', $customerId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function saleIdsByPaymentIds(array $paymentIds, int $tenantId): array
    {
        if ($paymentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.payment) AS payment_id, IDENTITY(a.sale) AS sale_id')
            ->join('a.payment', 'p')
            ->andWhere('a.payment IN (:paymentIds)')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('paymentIds', $paymentIds)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $paymentId = (int) $row['payment_id'];
            $map[$paymentId] ??= [];
            $map[$paymentId][] = (int) $row['sale_id'];
        }

        return $map;
    }
}
