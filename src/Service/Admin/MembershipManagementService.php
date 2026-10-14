<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Exception\Domain\MembershipViolationException;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestión de membresías desde el panel admin (Fase 6.2, ADR-0019).
 *
 * Las tres operaciones (alta, cambio de rol, baja) corren SIEMPRE en el
 * contexto de un super_admin (todas las rutas /admin están tras
 * ROLE_SUPER_ADMIN), por eso son globales: no usan el SQLFilter de
 * tenant, que el TenantFilterListener deja deshabilitado para
 * ROLE_SUPER_ADMIN (ADR-0016).
 *
 * Los invariantes se validan AQUÍ, en el servidor, y no en la vista: la
 * UI solo ofrece opciones válidas, pero un POST directo no debe poder
 * dejar un negocio sin usuarios, un usuario sin acceso, un negocio sin
 * administradores o una membresía duplicada.
 *
 * Importante (ADR-0016): las membresías no se traducen a roles de
 * Symfony. ROLE_ADMIN/ROLE_USER son ROLES DE MEMBRESÍA en
 * TenantMembership, y el rol del token no cambia al cambiar de negocio.
 * Esto es lo que hace segura la gestión desde /admin.
 */
final class MembershipManagementService
{
    /**
     * Roles de membresía válidos (allow-list; nada fuera de esta lista
     * llega a la BD).
     */
    public const VALID_ROLES = [
        TenantMembership::ROLE_ADMIN,
        TenantMembership::ROLE_USER,
    ];

    public function __construct(
        private readonly TenantMembershipRepositoryInterface $memberships,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Agrega la membresía de $user a $tenant con el rol indicado.
     *
     * @throws MembershipViolationException si $user ya pertenece a $tenant
     *         o si el rol no es válido (allow-list).
     */
    public function add(AppUser $user, Tenant $tenant, string $role): TenantMembership
    {
        $role = $this->assertValidRole($role);

        foreach ($this->memberships->findByUser($user) as $existing) {
            if ($existing->getTenant() === $tenant) {
                throw MembershipViolationException::alreadyMember($user->getEmail());
            }
        }

        $membership = new TenantMembership($user, $tenant);
        $membership->setRole($role);

        $user->addMembership($membership);

        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    /**
     * Cambia el rol de la membresía (p. ej. promover a administrador o
     * degradar a usuario).
     *
     * No-op silencioso si el rol ya es el que se pide: una petición
     * repetida no debe fallar ni dejar rastro.
     *
     * @throws MembershipViolationException si al degradar quedaría el
     *         negocio sin ningún administrador, o si el rol no es válido.
     */
    public function changeRole(TenantMembership $membership, string $role): void
    {
        $role = $this->assertValidRole($role);

        if ($membership->getRole() === $role) {
            return;
        }

        $isDemotion = $membership->getRole() === TenantMembership::ROLE_ADMIN
            && $role !== TenantMembership::ROLE_ADMIN;

        if ($isDemotion && $this->countAdmins($membership->getTenant()) <= 1) {
            throw MembershipViolationException::lastAdmin();
        }

        $membership->setRole($role);
        $this->em->flush();
    }

    /**
     * Quita la membresía (baja del negocio). El usuario NO se borra: se
     * queda sin ese acceso y conserva el resto de sus membresías.
     *
     * @throws MembershipViolationException si sería la última membresía
     *         del negocio, la última del usuario, o si es al único
     *         administrador (en ese orden: primero lo más grueso, el
     *         negocio, que deja de ser operable).
     */
    public function remove(TenantMembership $membership): void
    {
        $tenant = $membership->getTenant();
        $user = $membership->getUser();

        // Lo más grueso primero: el negocio sin usuarios.
        if (\count($this->memberships->findByTenant($tenant)) <= 1) {
            throw MembershipViolationException::lastMembershipOfTenant();
        }

        // Después: el usuario sin acceso (este panel admin no puede
        // dejar a alguien sin ningún negocio).
        if (\count($this->memberships->findByUser($user)) <= 1) {
            throw MembershipViolationException::lastMembershipOfUser();
        }

        // Por último, el administrador irreemplazable.
        if ($membership->getRole() === TenantMembership::ROLE_ADMIN
            && $this->countAdmins($tenant) <= 1) {
            throw MembershipViolationException::lastAdmin();
        }

        $user->removeMembership($membership);
        $this->em->remove($membership);
        $this->em->flush();
    }

    /**
     * @throws MembershipViolationException si el rol no está en la allow-list.
     */
    private function assertValidRole(string $role): string
    {
        if (!\in_array($role, self::VALID_ROLES, true)) {
            throw MembershipViolationException::invalidRole($role);
        }

        return $role;
    }

    private function countAdmins(Tenant $tenant): int
    {
        return \count(array_filter(
            $this->memberships->findByTenant($tenant),
            static fn (TenantMembership $membership): bool => $membership->getRole() === TenantMembership::ROLE_ADMIN,
        ));
    }
}
