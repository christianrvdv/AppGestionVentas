<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Product;

/**
 * Puerto de persistencia para productos.
 * Implementación Doctrine: App\Repository\ProductRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface ProductRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?Product;

    /**
     * @return Product[]
     */
    public function findActiveByTenant(int $tenantId): array;

    /**
     * @return Product[]
     */
    public function findByNameOrSku(int $tenantId, string $term, int $limit = 20): array;

    /**
     * Ranking de productos más rentables en un período.
     * Por defecto EXCLUYE ventas anuladas: rankear con datos contaminados
     * produce decisiones de negocio erróneas.
     *
     * @return array<int, array{id: int, name: string, totalProfit: string}>
     */
    public function findMostProfitable(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int                $limit = 10,
        bool               $includeVoided = false
    ): array;
}
