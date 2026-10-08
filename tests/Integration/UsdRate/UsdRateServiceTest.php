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

    /**
     * [FIX-9] Misma fecha dos veces: la segunda NO sobrescribe, crea una
     * corrección encadenada (isCorrection = true, supersedes = la anterior).
     *
     * Cubre el escenario que antes quedaba fuera de alcance: sin forzar el
     * tipo DATE del parámetro, findEffectiveByDate() no hallaba la fila en
     * SQLite y el "existing" salía siempre null.
     */
    public function test_set_rate_misma_fecha_crea_correccion_encadenada(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $first = $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-01'), '350.5000', $user);
        $second = $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-01'), '355.0000', $user, 'corrección');

        $this->assertFalse($first->isCorrection());
        $this->assertNull($first->getSupersedes());

        $this->assertTrue($second->isCorrection(), 'La segunda llamada en la misma fecha debe crear una corrección.');
        $this->assertNotNull($second->getSupersedes(), 'La corrección debe apuntar a la tasa anterior.');
        $this->assertSame($first->getId(), $second->getSupersedes()->getId());
        $this->assertNotSame($first->getId(), $second->getId());

        $effective = $this->usdRateService->getEffectiveFor(new \DateTimeImmutable('2026-10-01'));
        $this->assertNotNull($effective, 'getEffectiveByDate debe encontrar la fila en SQLite.');
        $this->assertSame($second->getId(), $effective->getId(), 'La efectiva es la última insertada (la corrección).');
        $this->assertSame('355.0000', $effective->getRate());
        $this->assertSame('355.0000', $this->usdRateService->getCurrent()->getRate());
    }

    public function test_get_effective_for_devuelve_la_tasa_de_la_fecha(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);

        $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-01'), '350.5000', $user);
        $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-02'), '360.0000', $user);

        $effective = $this->usdRateService->getEffectiveFor(new \DateTimeImmutable('2026-10-01'));

        $this->assertNotNull($effective, 'getEffectiveFor(2026-10-01) debe devolver la tasa de esa fecha en SQLite.');
        $this->assertSame('350.5000', $effective->getRate());
        $this->assertFalse($effective->isCorrection());
        $this->assertSame('2026-10-01', $effective->getRateDate()->format('Y-m-d'));

        $this->assertNull(
            $this->usdRateService->getEffectiveFor(new \DateTimeImmutable('2026-09-30')),
            'Sin tasa para esa fecha debe devolver null.'
        );
    }

    public function test_sin_tenant_en_contexto_no_explota(): void
    {
        $this->tenantContext->clear();

        $this->assertNull($this->usdRateService->getCurrent());

        $this->expectException(\LogicException::class);
        $this->usdRateService->setRate(new \DateTimeImmutable('2026-10-01'), '350.0000');
    }
}
