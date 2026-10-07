<?php

namespace App\Tests\Factory;

use App\Entity\Product;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Test\Factories;

final class ProductFactory extends Factories
{
    protected static string $class = Product::class;

    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->word() . ' ' . self::faker()->word(),
            'sku' => self::faker()->unique()->bothify('SKU-####'),
            'category' => self::faker()->optional()->word(),
            'defaultMarginPct' => self::faker()->optional()->randomFloat(2, 10, 60),
            'isActive' => true,
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (Product $product) {
                $product->onPrePersist();
            });
    }

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): Product
    {
        return static::create($attributes)->object();
    }
}