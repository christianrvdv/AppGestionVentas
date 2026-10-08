<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Customer;
use App\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Clientes de ejemplo del tenant Demo.
 */
final class CustomerFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $tenant = $this->getReference(TenantFixture::REF_DEMO, Tenant::class);

        $customers = [
            ['Almacén El Sol', '1155550001', 'elsol@demo.local'],
            ['Kiosco La Esquina', '1155550002', 'esquina@demo.local'],
            ['Bar Central', '1155550003', 'central@demo.local'],
        ];

        foreach ($customers as [$name, $phone, $email]) {
            $customer = new Customer();
            $customer->setTenant($tenant);
            $customer->setName($name);
            $customer->setPhone($phone);
            $customer->setEmail($email);
            $customer->setIsActive(true);

            $manager->persist($customer);
        }

        $manager->flush();
    }

    /**
     * @return string[]
     */
    public static function getGroups(): array
    {
        return ['demo'];
    }

    /**
     * @return string[]
     */
    public function getDependencies(): array
    {
        return [TenantFixture::class];
    }
}
