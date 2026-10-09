<?php

namespace App\Tests\Factory;

use App\Entity\Tenant;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Tenant>
 */
final class TenantFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Tenant::class;
    }

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
}