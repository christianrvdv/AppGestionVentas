<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\SaleLine;

/**
 * Puerto de persistencia para líneas de venta.
 * Implementación Doctrine: App\Repository\SaleLineRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface SaleLineRepositoryInterface
{
    /**
     * @param bool $includeVoided Si true, incluye líneas de ventas anuladas (auditoría).
     *
     * @return SaleLine[]
     */
    public function findByInvestmentItem(
        int  $itemId,
        int  $tenantId,
        bool $includeVoided = false
    ): array;

    /**
     * @return SaleLine[]
     */
    public function findBySale(int $saleId, int $tenantId): array;

    /**
     * @param bool $includeVoided Si true, incluye líneas de ventas anuladas (auditoría).
     *
     * @return SaleLine[]
     */
    public function findByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): array;

    public function sumCostRecoveredByItem(
        int  $itemId,
        int  $tenantId,
        bool $includeVoided = false
    ): string;

    public function sumGrossProfitByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): string;

    public function sumRecognizedProfitByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): string;

    public function countUnitsSoldByItem(
        int  $itemId,
        int  $tenantId,
        bool $includeVoided = false
    ): int;
}
