<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\InventoryMovement;

/**
 * Puerto de persistencia para movimientos de inventario (entradas, salidas,
 * pérdidas, ventas, devoluciones y ajustes).
 * Implementación Doctrine: App\Repository\InventoryMovementRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface InventoryMovementRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?InventoryMovement;

    public function getQuantityDeltaSum(int $itemId, int $tenantId): int;

    /**
     * @return array<int, array{in: int, out: int, balance: int}>
     */
    public function getStockByItem(int $tenantId): array;

    public function getSoldQuantity(int $itemId, int $tenantId): int;

    public function getLostQuantity(int $itemId, int $tenantId): int;

    /**
     * Cantidad total perdida (movimientos LOSS) para una inversión completa.
     */
    public function getLostQuantityByInvestment(int $investmentId, int $tenantId): int;

    /**
     * Capital perdido usando el costo histórico.
     */
    public function getLostCapital(int $itemId, int $tenantId): string;

    /**
     * Capital perdido usando el costo revaluado (a tasa actual).
     * Si no hay revaluación se cae al histórico.
     */
    public function getLostCapitalCurrent(int $itemId, int $tenantId): string;

    /**
     * @return InventoryMovement[]
     */
    public function findByItem(int $itemId, int $tenantId): array;

    public function existsForReference(
        int    $tenantId,
        string $referenceType,
        int    $referenceId,
        string $movementType
    ): bool;

    public function findByIdempotencyKey(int $tenantId, string $idempotencyKey): ?InventoryMovement;

    public function hasEnoughStock(int $itemId, int $tenantId, int $quantity): bool;

    /**
     * @return InventoryMovement[]
     */
    public function findByDateRange(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array;

    /**
     * @return InventoryMovement[]
     */
    public function findByType(int $tenantId, string $movementType): array;

    /**
     * @return array<int, array{in: int, out: int, balance: int}>
     */
    public function getStockByInvestment(int $investmentId, int $tenantId): array;

    public function countLossesWithoutSnapshot(int $tenantId): int;
}
