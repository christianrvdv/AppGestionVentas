<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AppUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rechaza cuentas inactivas antes de autenticar (login y remember_me).
 *
 * checkPostAuth queda vacío: la única condición de cuenta se valida en
 * checkPreAuth. Ambos métodos deben ser baratos y sin efectos secundarios
 * (se ejecutan en cada re-autenticación, no solo en el login).
 */
final class AppUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof AppUser && !$user->isActive()) {
            throw new DisabledException('La cuenta está deshabilitada.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
