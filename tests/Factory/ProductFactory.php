<?php

namespace App\Tests\Factory;

use App\Entity\Product;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Product>
 */
final class ProductFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Product::class;
    }

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
}