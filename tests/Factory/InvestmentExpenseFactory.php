<?php

namespace App\Tests\Factory;

use App\Entity\InvestmentExpense;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Test\Factories;

final class InvestmentExpenseFactory extends Factories
{
    protected static string $class = InvestmentExpense::class;

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
            'expenseDate' => self::faker()->dateTimeImmutableThisMonth(),
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

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): InvestmentExpense
    {
        return static::create($attributes)->object();
    }
}