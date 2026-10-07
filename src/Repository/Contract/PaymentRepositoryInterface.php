<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Payment;

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

    public function sumByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): string;
}
