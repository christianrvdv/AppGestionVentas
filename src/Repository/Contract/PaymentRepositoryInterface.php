<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\DTO\Query\AvailableAdvance;
use App\Entity\Payment;
use Doctrine\ORM\QueryBuilder;

/**
 * Puerto de persistencia para pagos de clientes y ventas.
 * Implementación Doctrine: App\Repository\PaymentRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface PaymentRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?Payment;

    /**
     * QueryBuilder paginable para el listado web (Fase 3d). Filtra siempre
     * por tenant (explícito, sin depender del SQLFilter) y opcionalmente
     * por cliente, venta, rango de fechas y método.
     *
     * El filtro de venta es un EXISTS sobre allocations y no un join a la
     * colección (desde ADR-0018 el vínculo es M:N): el COUNT que el
     * paginador necesita debe quedarse con las filas de payment, y un join
     * a una colección con varias allocations duplicaría filas. Tampoco hay
     * GROUP BY. Se ordena por paymentDate DESC con id DESC de desempate, un
     * orden estable que no repite ni salta filas al paginar.
     *
     * Un parámetro inválido (0, null o vacío) se ignora: el listado no
     * debe fallar porque alguien editó la URL a mano.
     */
    public function createPaginatedQueryBuilder(
        int                 $tenantId,
        ?int                $customerId = null,
        ?int                $saleId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?string             $method = null
    ): QueryBuilder;

    /**
     * Pagos con alguna allocation sobre la venta (no el pago completo
     * apuntando a la venta como antes: desde ADR-0018 un pago puede
     * repartirse entre varias ventas).
     *
     * @return Payment[]
     */
    public function findBySale(int $saleId, int $tenantId): array;

    /**
     * @return Payment[]
     */
    public function findByCustomer(int $customerId, int $tenantId): array;

    /**
     * Dinero cobrado de una venta: suma de sus allocations, no de los
     * pagos completos.
     */
    public function sumBySale(int $saleId, int $tenantId): string;

    /**
     * Dinero ENTRADO de un cliente: la suma de sus pagos, aplicados o no.
     * Mide dinero recibido, no dinero aplicado; no se cambió por
     * allocations a propósito (ver docblock de la implementación).
     */
    public function sumByCustomer(int $customerId, int $tenantId): string;

    /**
     * Anticipos del cliente con saldo todavía por aplicar: amount −
     * Σ allocations > 0 (Fase 3f, ADR-0018).
     *
     * @return AvailableAdvance[]
     */
    public function findAvailableAdvancesByCustomer(int $customerId, int $tenantId): array;

    /**
     * Saldo a favor del cliente en anticipos: lo que pagó menos lo que ya
     * cubre ventas.
     */
    public function sumAvailableAdvancesByCustomer(int $customerId, int $tenantId): string;

    public function sumByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): string;
}
