<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Customer;
use Doctrine\ORM\QueryBuilder;

/**
 * Puerto de persistencia para clientes.
 * Implementación Doctrine: App\Repository\CustomerRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface CustomerRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?Customer;

    /**
     * Query paginable del listado: filtra por tenant y, si $q no está vacío,
     * por nombre, teléfono o email (case-insensitive). El orden es estable
     * (name, id) para que la paginación no repita ni salte filas entre páginas.
     */
    public function createPaginatedQueryBuilder(int $tenantId, ?string $q): QueryBuilder;

    /**
     * @return Customer[]
     */
    public function findActiveByTenant(int $tenantId): array;

    /**
     * @return Customer[]
     */
    public function findByNameOrPhone(int $tenantId, string $term, int $limit = 20): array;

    public function findByPhone(int $tenantId, string $phone): ?Customer;

    public function findByEmail(int $tenantId, string $email): ?Customer;

    public function countActiveByTenant(int $tenantId): int;
}
