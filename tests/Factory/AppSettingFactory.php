<?php

namespace App\Tests\Factory;

use App\Entity\AppSetting;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<AppSetting>
 */
final class AppSettingFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return AppSetting::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'settingKey' => self::faker()->randomElement([
                AppSetting::KEY_DEFAULT_MARGIN_PCT,
                AppSetting::KEY_RECOVERY_MODE,
                AppSetting::KEY_BASE_CURRENCY,
                AppSetting::KEY_USD_RATE_CURRENT,
                AppSetting::KEY_USD_CONTROL_ENABLED,
                'currency_scale',
            ]),
            'settingValue' => self::faker()->randomElement(['40.00', 'PER_PRODUCT', 'ARS', '1000.0000', 'false', '2']),
            'tenant' => TenantFactory::new(),
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (AppSetting $setting) {
                $setting->onPrePersist();
            });
    }
}