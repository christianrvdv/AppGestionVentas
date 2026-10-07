<?php

namespace App\Tests\Factory;

use App\Entity\AppUser;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Test\Factories;

final class AppUserFactory extends Factories
{
    protected static string $class = AppUser::class;

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

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): AppUser
    {
        return static::create($attributes)->object();
    }
}