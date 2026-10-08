<?php

namespace App\Tests\Integration\UsdRate;

use App\Entity\AppSetting;
use App\Repository\Contract\AppSettingRepositoryInterface;
use App\Service\UsdRateService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [FIX-2] setRate() debe poder persistir el setting KEY_USD_RATE_CURRENT.
 *
 * Antes el servicio llamaba a findByKeyAndTenant(), método que no existe
 * ni en AppSettingRepositoryInterface ni en su implementación: toda llamada
 * a setRate() terminaba en Error (Call to undefined method) y la tasa nunca
 * se registraba.
 */
final class UsdRateServiceTest extends IntegrationTestCase
{
    private UsdRateService $usdRateService;

    private AppSettingRepositoryInterface $settingRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usdRateService = static::getContainer()->get(UsdRateService::class);
        $this->settingRepository = static::getContainer()->get(AppSettingRepositoryInterface::class);
    }

    public function test_set_rate_persiste_la_tasa_y_el_setting_vigente(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $rate = $this->usdRateService->setRate(
            new \DateTimeImmutable('2026-10-01'),
            '350.5000',
            $user,
            'prueba unit'
        );

        $this->assertNotNull($rate->getId());
        $this->assertSame('350.5000', $rate->getRate());
        $this->assertFalse($rate->isCorrection());

        $setting = $this->settingRepository->findSetting(
            $tenant->getId(),
            AppSetting::KEY_USD_RATE_CURRENT
        );

        $this->assertNotNull($setting, 'Debe existir el setting usd_rate_current después de setRate().');
        $this->assertSame('350.5000', $setting->getSettingValue());

        $current = $this->usdRateService->getCurrent();
        $this->assertNotNull($current);
        $this->assertSame('350.5000', $current->getRate());
    }

    /**
     * SEGUNDA llamada a setRate(): el setting debe refrescarse siempre a
     * la tasa vigente, que es justo lo que rompía [FIX-2].
     *
     * NOTA (observación, fuera de alcance): no se cubre aquí el escenario
     * de corrección "misma fecha" porque findEffectiveByDate() compara el
     * parámetro inferido como datetime_immutable ('2026-10-01 00:00:00')
     * contra una columna DATE ('2026-10-01'). En PostgreSQL el motor
     * convierte el literal a DATE y encuentra la fila; en SQLite (motor de
     * tests) es una comparación de texto que nunca coincide. El código de
     * corrección no se modifica por estar fuera de alcance.
     */
    public function test_set_rate_en_otra_fecha_actualiza_el_setting_vigente(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $first = $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-01'), '350.5000', $user);
        $second = $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-02'), '360.0000', $user);

        $this->assertFalse($first->isCorrection());
        $this->assertFalse($second->isCorrection());

        $setting = $this->settingRepository->findSetting(
            $tenant->getId(),
            AppSetting::KEY_USD_RATE_CURRENT
        );
        $this->assertSame('360.0000', $setting->getSettingValue());
        $this->assertSame('360.0000', $this->usdRateService->getCurrent()->getRate());
    }

    public function test_sin_tenant_en_contexto_no_explota(): void
    {
        $this->tenantContext->clear();

        $this->assertNull($this->usdRateService->getCurrent());

        $this->expectException(\LogicException::class);
        $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-01'), '350.0000');
    }
}
