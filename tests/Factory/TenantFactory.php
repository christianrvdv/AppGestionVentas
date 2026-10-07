<?php

namespace App\Tests\Factory;

use App\Entity\Tenant;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\Test\Factories;

final class TenantFactory extends Factories
{
    protected static string $class = Tenant::class;

    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->company(),
            'slug' => self::faker()->unique()->slug(2),
            'isActive' => true,
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (Tenant $tenant) {
                $tenant->onPrePersist();
            });
    }

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): Tenant
    {
        return static::create($attributes)->object();
    }
}