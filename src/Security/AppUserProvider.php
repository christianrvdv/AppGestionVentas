<?php

declare(strict_types=1);

namespace App\Security;

use App\Doctrine\TenantFilterGuard;
use App\Entity\AppUser;
use App\Repository\Contract\AppUserRepositoryInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Carga AppUser por email GLOBAL (ADR-0015).
 *
 * Flujo interactivo (form_login): el identifier es el email y NO hay
 * campo de negocio en el POST — el usuario es único en todo el sistema.
 * El tenant activo se decide DESPUÉS del login, en el listener de
 * LoginSuccessEvent (una membresía → la activa; varias → /select-tenant;
 * ninguna → /onboarding).
 *
 * Flujo de sesión (refreshUser): se recarga por id y se devuelve tal
 * cual; ya no hay claim de tenant en el usuario que validar (el tenant
 * vive en la sesión, y RequireTenantListener garantiza que exista antes
 * de tocar datos de negocio).
 *
 * ATENCIÓN: estas dos consultas son las ÚNICAS que deben cruzar tenants
 * (por diseño: el login resuelve identidad, no negocio). En workers
 * persistentes (FIX-6) el TenantFilter puede llegar habilitado con el
 * tenant de la request anterior, así que se deshabilita explícitamente
 * alrededor de los lookups y se restaura al salir. El listener vuelve a
 * setear el parámetro en kernel.request (prioridad 5), después del
 * firewall.
 *
 * @implements UserProviderInterface<AppUser>
 */
final class AppUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly AppUserRepositoryInterface $userRepository,
        private readonly TenantFilterGuard $filterGuard,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->filterGuard->withoutTenantFilter(function () use ($identifier): AppUser {
            $user = $this->userRepository->findOneByEmail($identifier);

            if ($user === null) {
                // Mensaje genérico: no distingue "email inexistente" de
                // cualquier otra causa (sin enumeración de cuentas).
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

        return $this->filterGuard->withoutTenantFilter(function () use ($user): AppUser {
            // Carga por id SIN filtro de tenant (ver docblock de la clase
            // y AppUserRepositoryInterface::findById). Si el usuario ya no
            // existe, la sesión se cierra.
            $refreshed = $this->userRepository->findById($user->getId());

            if ($refreshed === null) {
                throw new UserNotFoundException('Usuario no encontrado.');
            }

            return $refreshed;
        });
    }

    public function supportsClass(string $class): bool
    {
        return $class === AppUser::class || is_subclass_of($class, AppUser::class);
    }
}
