<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\AppUser;

/**
 * Puerto de persistencia para usuarios de la aplicación.
 * Implementación Doctrine: App\Repository\AppUserRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface AppUserRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?AppUser;

    public function findOneByEmailAndTenant(string $email, int $tenantId): ?AppUser;

    /**
     * @return AppUser[]
     */
    public function findActiveByTenant(int $tenantId): array;
}
