<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Tenant;

/**
 * Puerto de persistencia para tenants (empresas/organizaciones).
 * Implementación Doctrine: App\Repository\TenantRepository.
 */
interface TenantRepositoryInterface
{
    public function findBySlug(string $slug): ?Tenant;

    /**
     * @return Tenant[]
     */
    public function findActive(): array;
}
