<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AppSetting;
use App\Entity\Tenant;
use App\Entity\UsdRate;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Tasa USD de referencia del tenant Demo + sincroniza el setting
 * KEY_USD_RATE_CURRENT (misma responsabilidad que UsdRateService::setRate,
 * pero escrita directamente porque las fixtures no pasan por servicios
 * de aplicación).
 */
final class UsdRateFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public const RATE = '1200.0000';

    public function load(ObjectManager $manager): void
    {
        $tenant = $this->getReference(TenantFixture::REF_DEMO, Tenant::class);

        $rate = new UsdRate();
        $rate->setTenant($tenant);
        $rate->setRateDate(new \DateTimeImmutable('today'));
        $rate->setRate(self::RATE);
        $rate->setSourceNote('Tasa de referencia demo');
        $manager->persist($rate);

        $setting = new AppSetting();
        $setting->setTenant($tenant);
        $setting->setSettingKey(AppSetting::KEY_USD_RATE_CURRENT);
        $setting->setSettingValue(self::RATE);
        $manager->persist($setting);

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
        return [TenantFixture::class, AppSettingFixture::class];
    }
}
