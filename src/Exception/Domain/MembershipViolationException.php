<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Invariante de membresías violada en la gestión del panel admin
 * (Fase 6.2): duplicado, última membresía del negocio o del usuario, o
 * último administrador del negocio.
 *
 * Se lanza desde MembershipManagementService —que es quien decide— y el
 * controller la traduce en un flash de error, sin tocar la BD.
 */
final class MembershipViolationException extends DomainException
{
    public static function alreadyMember(string $email): self
    {
        return new self(sprintf('El usuario %s ya pertenece a este negocio.', $email));
    }

    public static function lastMembershipOfTenant(): self
    {
        return new self('No se puede quitar: es la última membresía del negocio; quedaría sin usuarios.');
    }

    public static function lastMembershipOfUser(): self
    {
        return new self('No se puede quitar: es la última membresía del usuario; se quedaría sin acceso.');
    }

    public static function lastAdmin(): self
    {
        return new self('El negocio necesita al menos un administrador.');
    }

    public static function invalidRole(string $role): self
    {
        return new self(sprintf('Rol de membresía no válido: "%s". Usa ROLE_ADMIN o ROLE_USER.', $role));
    }
}
