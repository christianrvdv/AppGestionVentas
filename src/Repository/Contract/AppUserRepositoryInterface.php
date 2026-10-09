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
 *
 * Excepción documentada: findById() NO filtra por tenant. Es el único
 * lookup cross-tenant permitido en la capa de repositorio y existe
 * exclusivamente para que AppUserProvider::refreshUser() valide la
 * sesión: carga el usuario por id y COMPARA su tenant persistido contra
 * el que declara el usuario de la sesión (rechaza con
 * UnsupportedUserException si difieren). Nunca devuelve datos ajenos:
 * un mismatch termina en excepción antes de usarse.
 */
interface AppUserRepositoryInterface
{
    public function findByIdAndTenant(int $id, int $tenantId): ?AppUser;

    /**
     * Lookup por id SIN filtro de tenant — solo para validación de
     * sesión en AppUserProvider::refreshUser (ver nota de la interfaz).
     */
    public function findById(int $id): ?AppUser;

    public function findOneByEmailAndTenant(string $email, int $tenantId): ?AppUser;

    /**
     * @return AppUser[]
     */
    public function findActiveByTenant(int $tenantId): array;
}
