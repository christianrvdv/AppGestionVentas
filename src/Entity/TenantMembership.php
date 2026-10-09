<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * Pertenencia de un usuario a un tenant (negocio), con su rol.
 *
 * Fase 3.5 (ADR-0015): el usuario es GLOBAL (email único en todo el
 * sistema) y la relación con los negocios vive en esta tabla puente.
 *
 * El tenant activo NO se deriva de esta fila en cada request: se guarda
 * en la sesión (active_tenant_id) al hacer login (si hay una sola
 * membresía) o al elegirlo en /select-tenant. TenantFilterListener lee
 * esa clave de sesión; nunca la membresía.
 *
 * Relación: unique (user_id, tenant_id) — un usuario no puede pertenecer
 * dos veces al mismo negocio. El rol es un string corto (ADR-0015 deja
 * fuera la gestión de roles más allá de ROLE_ADMIN/ROLE_USER).
 */
#[ORM\Entity(repositoryClass: TenantMembershipRepository::class)]
#[ORM\Table(name: 'tenant_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user_tenant', columns: ['user_id', 'tenant_id'])]
#[ORM\Index(name: 'idx_membership_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_membership_tenant', columns: ['tenant_id'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(
    fields: ['user', 'tenant'],
    errorPath: 'tenant',
    message: 'El usuario ya pertenece a este negocio.',
)]
class TenantMembership
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: AppUser::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private AppUser $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(
        name: 'tenant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Tenant $tenant;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $role = self::ROLE_USER;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(name: 'is_default', type: Types::BOOLEAN)]
    private bool $isDefault = false;

    public function __construct(AppUser $user, Tenant $tenant)
    {
        $this->user = $user;
        $this->tenant = $tenant;
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): AppUser
    {
        return $this->user;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
        return $this;
    }
}
