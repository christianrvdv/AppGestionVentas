<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AppSetting;
use App\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Settings por defecto del tenant Demo:
 *   - default_margin_pct = 40
 *   - base_currency      = ARS
 *   - recovery_mode      = PER_PRODUCT
 *   - currency_scale     = 2
 *   - usd_control_enabled = false
 */
final class AppSettingFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $tenant = $this->getReference(TenantFixture::REF_DEMO, Tenant::class);

        $settings = [
            AppSetting::KEY_DEFAULT_MARGIN_PCT => '40',
            AppSetting::KEY_BASE_CURRENCY => 'ARS',
            AppSetting::KEY_RECOVERY_MODE => 'PER_PRODUCT',
            AppSetting::KEY_CURRENCY_SCALE => '2',
            AppSetting::KEY_USD_CONTROL_ENABLED => 'false',
        ];

        foreach ($settings as $key => $value) {
            $setting = new AppSetting();
            $setting->setTenant($tenant);
            $setting->setSettingKey($key);
            $setting->setSettingValue($value);

            $manager->persist($setting);
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
