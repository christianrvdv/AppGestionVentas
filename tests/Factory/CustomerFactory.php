<?php

namespace App\Tests\Factory;

use App\Entity\Customer;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Customer>
 */
final class CustomerFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Customer::class;
    }

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
}