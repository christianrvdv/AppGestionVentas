<?php

namespace App\Tests\Factory;

use App\Entity\Investment;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Investment>
 */
final class InvestmentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Investment::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'code' => 'INV-' . self::faker()->unique()->numberBetween(1000, 9999),
            'investmentDate' => new \DateTimeImmutable(self::faker()->dateTimeThisMonth()->format('Y-m-d H:i:s')),
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
}