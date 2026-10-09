<?php

namespace App\Tests\Factory;

use App\Entity\UsdRate;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<UsdRate>
 */
final class UsdRateFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return UsdRate::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'rateDate' => new \DateTimeImmutable(self::faker()->dateTimeThisMonth()->format('Y-m-d H:i:s')),
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
}