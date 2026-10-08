<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Product;
use App\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Catálogo mínimo de productos del tenant Demo.
 */
final class ProductFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $tenant = $this->getReference(TenantFixture::REF_DEMO, Tenant::class);

        $products = [
            ['Café molido 500g', 'CAF-500', 'Bebidas'],
            ['Azúcar 1kg', 'AZU-1000', 'Almacén'],
            ['Yerba mate 1kg', 'YER-1000', 'Bebidas'],
            ['Galletitas dulces', 'GAL-200', 'Almacén'],
        ];

        foreach ($products as [$name, $sku, $category]) {
            $product = new Product();
            $product->setTenant($tenant);
            $product->setName($name);
            $product->setSku($sku);
            $product->setCategory($category);
            $product->setIsActive(true);

            $manager->persist($product);
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
