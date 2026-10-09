<?php

declare(strict_types=1);

namespace App\Security;

use App\Doctrine\Filter\TenantFilter;
use App\Entity\AppUser;
use App\Repository\Contract\AppUserRepositoryInterface;
use App\Repository\Contract\TenantRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Carga AppUser por email + tenant slug (ADR-0009, opción B).
 *
 * Flujo interactivo (form_login): el email viene como identifier y el
 * tenant_slug como campo extra del POST de /login, leído vía RequestStack
 * (el provider no recibe el request de otra forma; es el patrón estándar
 * para form_login multi-tenant).
 *
 * Flujo de sesión (refreshUser): se recarga por id y se VALIDA que el
 * tenant persistido coincida con el que declara el usuario de la sesión;
 * un mismatch lanza UnsupportedUserException (cierre de sesión) en lugar
 * de confiar en el claim de la sesión. No depende del request.
 *
 * ATENCIÓN: estas dos consultas son las ÚNICAS que deben cruzar tenants
 * (por diseño: el login resuelve el tenant). En workers persistentes
 * (FIX-6) el TenantFilter puede llegar habilitado con el tenant de la
 * request anterior, así que se deshabilita explícitamente alrededor de
 * los lookups y se restaura al salir. El listener vuelve a setear el
 * parámetro en kernel.request (prioridad 5), después del firewall.
 */
final class AppUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly AppUserRepositoryInterface $userRepository,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $tenantSlug = trim(
            $this->requestStack->getCurrentRequest()?->request->getString('tenant_slug') ?? ''
        );

        return $this->withoutTenantFilter(function () use ($identifier, $tenantSlug): AppUser {
            $tenant = $tenantSlug !== ''
                ? $this->tenantRepository->findBySlug($tenantSlug)
                : null;

            $user = $tenant !== null
                ? $this->userRepository->findOneByEmailAndTenant($identifier, $tenant->getId())
                : null;

            if ($user === null) {
                // Mensaje genérico: no se distingue "slug inexistente" de
                // "email inexistente en ese tenant" (sin enumeración).
                throw new UserNotFoundException('Credenciales inválidas.');
            }

            return $user;
        });
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AppUser) {
            throw new UnsupportedUserException(sprintf('Clase de usuario no soportada: "%s".', $user::class));
        }

        return $this->withoutTenantFilter(function () use ($user): AppUser {
            // Carga por id SIN filtro de tenant y valida contra el tenant
            // que declara el usuario de la sesión. Si la sesión estuviera
            // manipulada o desactualizada (tenant ≠ persistido), se
            // rechaza con UnsupportedUserException y la sesión se cierra,
            // en lugar de confiar en el claim de la sesión.
            $refreshed = $this->userRepository->findById($user->getId());

            if ($refreshed === null) {
                throw new UserNotFoundException('Usuario no encontrado.');
            }

            if ($refreshed->getTenant()->getId() !== $user->getTenant()->getId()) {
                throw new UnsupportedUserException(
                    'El usuario de la sesión no pertenece al tenant declarado.'
                );
            }

            return $refreshed;
        });
    }

    public function supportsClass(string $class): bool
    {
        return $class === AppUser::class || is_subclass_of($class, AppUser::class);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withoutTenantFilter(callable $callback): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled(TenantFilter::NAME);

        if ($wasEnabled) {
            $filters->disable(TenantFilter::NAME);
        }

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $filters->enable(TenantFilter::NAME);
            }
        }
    }
}
