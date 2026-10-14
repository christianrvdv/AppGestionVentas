<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\Query\AvailableAdvance;
use App\Entity\Payment;
use App\Repository\Contract\PaymentAllocationRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Payment>
 *
 * CONVENCIÓN DE BORRADO LÓGICO: los pagos no tienen flag de anulación y
 * ningún método filtra por Sale.voidedAt. Es correcto por construcción:
 * la política de anulación (ADR-0003, SaleVoidService) solo permite
 * anular ventas SIN pagos aplicados, así que una venta anulada jamás
 * tiene allocations que excluir. Sumar pagos de una venta anulada da 0.
 *
 * DESDE LA FASE 3f (ADR-0018) el vínculo pago↔venta es PaymentAllocation,
 * no la columna payment.sale_id (eliminada). Cualquier pregunta del tipo
 * "cuánto se cobró de esta venta" se resuelve sobre allocations; "cuánto
 * pagó este cliente" sigue resolviéndose sobre payment.amount porque mide
 * dinero ENTRADO, que no cambia al repartirlo entre ventas.
 */
#[AsAlias(PaymentRepositoryInterface::class)]
class PaymentRepository extends ServiceEntityRepository implements PaymentRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PaymentAllocationRepositoryInterface $allocationRepository
    ) {
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

        // EXISTS en lugar de un join a la colección: el COUNT que el
        // paginador arma sobre este QueryBuilder sigue contando filas de
        // payment (una fila por allocation duplicaría la página), y el
        // contrato de "sin joins a colecciones ni GROUP BY" se mantiene.
        if ($saleId !== null && $saleId > 0) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s a WHERE a.payment = p AND a.sale = :saleId)',
                \App\Entity\PaymentAllocation::class
            ))
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
     * Pagos con alguna allocation sobre la venta.
     *
     * Como unique (payment_id, sale_id), cada pago aparece una sola vez
     * aunque tenga varias allocations: no hace falta DISTINCT.
     *
     * @return Payment[]
     */
    public function findBySale(int $saleId, int $tenantId): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.allocations', 'a')
            ->andWhere('a.sale = :saleId')
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

    /**
     * Dinero cobrado de una venta: suma de sus allocations, no de los
     * pagos completos (un anticipo puede cubrir esta venta y otras).
     */
    public function sumBySale(int $saleId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(a.amount) AS total')
            ->join('p.allocations', 'a')
            ->andWhere('a.sale = :saleId')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('saleId', $saleId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    /**
     * Dinero ENTRADO de un cliente: la suma de sus pagos, aplicados o no.
     *
     * No se cambia por allocations a propósito: restarle las allocations
     * contaría dos veces el anticipo (una vez acá y otra en el saldo) y
     * haría que un anticipo sin aplicar no computara como pagado, inflando
     * el saldo que debe el cliente.
     */
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
     * Anticipos del cliente con saldo todavía por aplicar (Fase 3f).
     *
     * Un pago califica cuando amount − Σ allocations > 0: ya no basta con
     * que no tenga venta, porque desde ADR-0018 un pago puede estar
     * parcialmente aplicado y seguir dejando remanente.
     *
     * @return AvailableAdvance[]
     */
    public function findAvailableAdvancesByCustomer(int $customerId, int $tenantId): array
    {
        $payments = $this->findByCustomer($customerId, $tenantId);

        if ($payments === []) {
            return [];
        }

        $paymentIds = array_map(static fn (Payment $payment): int => $payment->getId(), $payments);
        $allocated = $this->allocationRepository->sumAllocatedByPaymentIds($paymentIds, $tenantId);

        $advances = [];
        foreach ($payments as $payment) {
            $available = bcsub($payment->getAmount(), $allocated[$payment->getId()] ?? '0.00', 2);

            if (bccomp($available, '0', 2) > 0) {
                $advances[] = new AvailableAdvance($payment, $available);
            }
        }

        return $advances;
    }

    /**
     * Saldo a favor del cliente en anticipos: lo que pagó menos lo que ya
     * cubre ventas. Como las allocations de un pago nunca superan su
     * amount, la diferencia agregada nunca queda negativa.
     */
    public function sumAvailableAdvancesByCustomer(int $customerId, int $tenantId): string
    {
        $paid = $this->sumByCustomer($customerId, $tenantId);
        $applied = $this->allocationRepository->sumAllocatedByCustomer($customerId, $tenantId);

        $available = bcsub($paid, $applied, 2);

        return bccomp($available, '0', 2) < 0 ? '0.00' : $available;
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
