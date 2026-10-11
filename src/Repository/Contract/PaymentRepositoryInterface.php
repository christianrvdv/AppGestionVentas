<?php

declare(strict_types=1);

namespace App\Repository\Contract;

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
     * Sin joins a colecciones ni GROUP BY a propósito: el COUNT que el
     * paginador necesita debe quedarse con las filas de la página. Se
     * ordena por paymentDate DESC con id DESC de desempate, un orden
     * estable que no repite ni salta filas al paginar.
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
     * @return Payment[]
     */
    public function findBySale(int $saleId, int $tenantId): array;

    /**
     * @return Payment[]
     */
    public function findByCustomer(int $customerId, int $tenantId): array;

    public function sumBySale(int $saleId, int $tenantId): string;

    public function sumByCustomer(int $customerId, int $tenantId): string;

    /**
     * Pagos no aplicados a una venta (anticipos / a cuenta del cliente).
     *
     * @return Payment[]
     */
    public function findUnappliedByCustomer(int $customerId, int $tenantId): array;

    /**
     * Suma de los pagos no aplicados a ninguna venta: el dinero del
     * cliente que todavía no cubre un cobro concreto.
     */
    public function sumUnappliedByCustomer(int $customerId, int $tenantId): string;

    public function sumByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): string;
}
