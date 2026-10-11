<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Sale;
use Doctrine\ORM\QueryBuilder;

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
     * QueryBuilder paginable para el listado web (Fase 3c.1). Filtra
     * siempre por tenant (explícito, sin depender del SQLFilter) y
     * opcionalmente por inversión, cliente y rango de fechas.
     *
     * Las anuladas se EXCLUYEN por defecto: una venta anulada no existió
     * a efectos del negocio y en el listado estorbaría; el flag de
     * auditoría las incluye. Un parámetro inválido (0/null) se ignora.
     *
     * Sin joins a colecciones ni GROUP BY a propósito: el COUNT que el
     * paginador necesita debe quedarse con las filas de la página. La
     * ganancia bruta de cada fila se resuelve aparte con
     * sumGrossProfitBySaleIds().
     *
     * Orden estable (saleDate DESC, id DESC) para que no se repitan ni
     * se salten ventas al paginar mientras se registran nuevas.
     */
    public function createPaginatedQueryBuilder(
        int                 $tenantId,
        ?int                $investmentId = null,
        ?int                $customerId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        bool                $includeVoided = false
    ): QueryBuilder;

    /**
     * Ganancia BRUTA por venta para un conjunto de ids (la página actual
     * del listado). Una sola consulta agrupada; los ids sin líneas no
     * aparecen (no hay fila con ganancia cero que confundir con un 0).
     *
     * @param int[] $saleIds
     * @return array<int, string> saleId => suma de grossProfitLine
     */
    public function sumGrossProfitBySaleIds(array $saleIds, int $tenantId): array;

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
