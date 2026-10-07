<?php

namespace App\Tests\Factory;

use App\Entity\AppSetting;
use Zenstruck\Foundry\Persistence\PersistentProxy;
use Zenstruck\Foundry\Test\Factories;

final class AppSettingFactory extends Factories
{
    protected static string $class = AppSetting::class;

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

    public static function create(array|callable $attributes = []): PersistentProxy
    {
        return static::instantiate($attributes)->_save();
    }

    public static function createOne(array $attributes = []): AppSetting
    {
        return static::create($attributes)->object();
    }
}