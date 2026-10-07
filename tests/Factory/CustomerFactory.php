<?php

namespace App\Tests\Factory;

use App\Entity\Customer;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Test\Factories;

final class CustomerFactory extends Factories
{
    protected static string $class = Customer::class;

    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->name(),
            'phone' => self::faker()->optional()->phoneNumber(),
            'email' => self::faker()->optional()->safeEmail(),
            'address' => self::faker()->optional()->address(),
            'notes' => self::faker()->optional()->sentence(),
            'isActive' => true,
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (Customer $customer) {
                $customer->onPrePersist();
            });
    }

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): Customer
    {
        return static::create($attributes)->object();
    }
}