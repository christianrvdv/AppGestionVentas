<?php

namespace App\Tests\Factory;

use App\Entity\UsdRate;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Test\Factories;

final class UsdRateFactory extends Factories
{
    protected static string $class = UsdRate::class;

    protected function defaults(): array|callable
    {
        return [
            'rateDate' => self::faker()->dateTimeImmutableThisMonth(),
            'rate' => self::faker()->randomFloat(4, 100, 1500),
            'isCorrection' => false,
            'supersedes' => null,
            'createdBy' => AppUserFactory::new(),
            'sourceNote' => self::faker()->optional()->sentence(),
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (UsdRate $rate) {
                $rate->onPrePersist();
            });
    }

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): UsdRate
    {
        return static::create($attributes)->object();
    }
}