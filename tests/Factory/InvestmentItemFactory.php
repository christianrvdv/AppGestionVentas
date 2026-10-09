<?php

namespace App\Tests\Factory;

use App\Entity\InvestmentItem;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<InvestmentItem>
 */
final class InvestmentItemFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return InvestmentItem::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'quantity' => self::faker()->numberBetween(1, 100),
            'unitCost' => self::faker()->randomFloat(2, 10, 1000),
            'unitCostUsd' => null,
            'allocatedExpense' => '0.00',
            'allocatedExpenseUsd' => null,
            'realUnitCost' => '0.00',
            'realUnitCostUsd' => null,
            'currentRealUnitCost' => '0.00',
            'suggestedMarginPct' => '0.00',
            'suggestedPrice' => '0.00',
            'currentSuggestedPrice' => '0.00',
            'fixedPrice' => null,
            'notes' => null,
            'tenant' => TenantFactory::new(),
            'investment' => InvestmentFactory::new(),
            'product' => ProductFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (InvestmentItem $item) {
                $item->onPrePersist();
            });
    }
}