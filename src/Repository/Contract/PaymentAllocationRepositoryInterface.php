<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\PaymentAllocation;

/**
 * Puerto de persistencia de las aplicaciones pago↔venta (Fase 3f, ADR-0018).
 * Implementación Doctrine: App\Repository\PaymentAllocationRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. PaymentAllocation no
 * tiene columna de tenant, así que el filtro se hace SIEMPRE a través del
 * join con Payment (payment.tenant), que es la única fuente de verdad.
 */
interface PaymentAllocationRepositoryInterface
{
    /**
     * Aplicaciones hechas sobre una venta, en el mismo orden en el que
     * PaymentRepository::findBySale devolvía los pagos (fecha de pago ASC,
     * id ASC de desempate).
     *
     * @return PaymentAllocation[]
     */
    public function findBySale(int $saleId, int $tenantId): array;

    /**
     * Aplicaciones de un pago concreto: las ventas que cubre y con qué
     * monto (detalle /pagos/{id}).
     *
     * @return PaymentAllocation[]
     */
    public function findByPayment(int $paymentId, int $tenantId): array;

    /**
     * Total ya aplicado de un pago: amount − sumByPayment es el remanente
     * disponible para aplicar a otra venta.
     */
    public function sumByPayment(int $paymentId, int $tenantId): string;

    /**
     * Si el pago ya tiene una allocation sobre esa venta.
     *
     * La unique (payment_id, sale_id) lo impide en la base, pero la regla
     * es de negocio: un anticipo se aplica UNA VEZ a cada venta, y sin
     * esta consulta el doble submit reventaría como violación de índice
     * (500) en vez de un mensaje claro.
     */
    public function hasAllocationForSale(int $paymentId, int $saleId, int $tenantId): bool;

    /**
     * Total aplicado por los pagos de un cliente. amount − esto (medido
     * sobre los pagos del cliente) es su saldo a favor en anticipos.
     *
     * @param int[] $paymentIds
     *
     * @return array<int, string> paymentId => total aplicado (2 decimales)
     */
    public function sumAllocatedByPaymentIds(array $paymentIds, int $tenantId): array;

    /**
     * Total ya aplicado por todos los pagos de un cliente. Solo cuenta las
     * allocations de pagos QUE LE PERTENECEN: un pago sin cliente aplicado a
     * una venta suya no es dinero que él adelantó.
     */
    public function sumAllocatedByCustomer(int $customerId, int $tenantId): string;

    /**
     * Ventas cubiertas por cada uno de los pagos indicados, en una sola
     * consulta (el listado /pagos y el de pagos del cliente necesitan la
     * columna "Venta" por fila y una query por fila sería N+1).
     *
     * @param int[] $paymentIds
     *
     * @return array<int, int[]> paymentId => lista de saleId
     */
    public function saleIdsByPaymentIds(array $paymentIds, int $tenantId): array;
}
