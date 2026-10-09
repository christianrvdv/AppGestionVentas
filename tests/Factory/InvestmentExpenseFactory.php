<?php

namespace App\Tests\Factory;

use App\Entity\InvestmentExpense;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<InvestmentExpense>
 */
final class InvestmentExpenseFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return InvestmentExpense::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'category' => self::faker()->randomElement([
                InvestmentExpense::CATEGORY_TRANSPORT,
                InvestmentExpense::CATEGORY_FOOD,
                InvestmentExpense::CATEGORY_BATHROOM,
                InvestmentExpense::CATEGORY_SNACK,
                InvestmentExpense::CATEGORY_STORAGE,
                InvestmentExpense::CATEGORY_OTHER,
            ]),
            'description' => self::faker()->optional()->sentence(),
            'amount' => self::faker()->randomFloat(2, 10, 5000),
            'isAllocated' => true,
            'usdRateSnapshot' => null,
            'amountUsd' => null,
            'expenseDate' => new \DateTimeImmutable(self::faker()->dateTimeThisMonth()->format('Y-m-d H:i:s')),
            'tenant' => TenantFactory::new(),
            'investment' => InvestmentFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (InvestmentExpense $expense) {
                $expense->onPrePersist();
            });
    }
}