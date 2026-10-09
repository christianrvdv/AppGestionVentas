<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Product;
use Doctrine\ORM\QueryBuilder;

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
     * Query paginable del listado: filtra por tenant y, si $q no está vacío,
     * por nombre o SKU (case-insensitive). El orden es estable (name, id) para
     * que la paginación no repita ni salte filas entre páginas.
     */
    public function createPaginatedQueryBuilder(int $tenantId, ?string $q): QueryBuilder;

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
