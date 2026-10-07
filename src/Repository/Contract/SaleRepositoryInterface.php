<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Sale;

/**
 * Puerto de persistencia para ventas (cabeceras) y sus agregados por período.
 * Implementación Doctrine: App\Repository\SaleRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface SaleRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?Sale;

    /**
     * @param bool $includeVoided Si true, incluye ventas anuladas (auditoría).
     *
     * @return Sale[]
     */
    public function findByDateRange(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): array;

    public function sumSalesByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): string;

    /**
     * Ganancia BRUTA en el período (independiente del recoveryMode).
     * Por defecto excluye ventas anuladas.
     */
    public function sumProfitByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): string;

    /**
     * Ganancia RECONOCIDA en el período (según recoveryMode de cada inversión).
     * Por defecto excluye ventas anuladas.
     */
    public function sumRecognizedProfitByPeriod(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): string;

    public function sumSalesByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): string;

    /**
     * @return Sale[]
     */
    public function findByInvestment(
        int  $investmentId,
        int  $tenantId,
        bool $includeVoided = false
    ): array;

    /**
     * @return Sale[]
     */
    public function findByCustomer(
        int  $customerId,
        int  $tenantId,
        bool $includeVoided = false
    ): array;

    public function countByInvestment(int $investmentId, int $tenantId): int;

    /**
     * Totales diarios (venta + ganancia bruta).
     *
     * Se hacen DOS consultas porque DQL no permite subqueries en SELECT.
     * Se mergean por fecha en PHP.
     *
     * Se usa el alias "dayKey" en lugar de "date" para evitar
     * colisiones con palabras reservadas en PostgreSQL/SQLite.
     *
     * @return array<int, array{date: string, total: string, profit: string}>
     */
    public function dailyTotals(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool               $includeVoided = false
    ): array;
}
