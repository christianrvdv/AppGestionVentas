<?php

namespace App\Tests\Factory;

use App\Entity\Investment;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Test\Factories;

final class InvestmentFactory extends Factories
{
    protected static string $class = Investment::class;

    protected function defaults(): array|callable
    {
        return [
            'code' => 'INV-' . self::faker()->unique()->numberBetween(1000, 9999),
            'investmentDate' => self::faker()->dateTimeImmutableThisMonth(),
            'baseCurrency' => 'ARS',
            'usdRateSnapshot' => null,
            'currentUsdRate' => null,
            'totalMerchandiseCost' => '0.00',
            'totalExpenses' => '0.00',
            'totalInvestment' => '0.00',
            'allocationMethod' => Investment::ALLOCATION_METHOD_VALUE,
            'recoveryMode' => Investment::RECOVERY_PER_PRODUCT,
            'status' => Investment::STATUS_OPEN,
            'description' => null,
            'tenant' => TenantFactory::new(),
            'createdBy' => AppUserFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (Investment $investment) {
                $investment->onPrePersist();
            });
    }

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): Investment
    {
        return static::create($attributes)->object();
    }
}