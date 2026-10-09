<?php

namespace App\Tests\Factory;

use App\Entity\AppUser;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AppUser>
 */
final class AppUserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AppUser::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'passwordHash' => '$2y$13$dummy.hash.for.testing',
            'roles' => ['ROLE_USER'],
            'isActive' => true,
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (AppUser $user) {
                $user->onPrePersist();
            });
    }
}