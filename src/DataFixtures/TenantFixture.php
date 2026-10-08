<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Tenant de demostración: slug "demo".
 *
 * Todas las demás fixtures cuelgan de esta (getDependencies) y usan
 * la referencia TenantFixture::REF_DEMO.
 */
final class TenantFixture extends Fixture implements FixtureGroupInterface
{
    public const REF_DEMO = 'demo_tenant';

    public function load(ObjectManager $manager): void
    {
        $tenant = new Tenant();
        $tenant->setName('Demo');
        $tenant->setSlug('demo');
        $tenant->setIsActive(true);

        $manager->persist($tenant);
        $manager->flush();

        $this->addReference(self::REF_DEMO, $tenant);
    }

    /**
     * @return string[]
     */
    public static function getGroups(): array
    {
        return ['demo'];
    }
}
