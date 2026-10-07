<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppUser;
use App\Entity\AppSetting;
use App\Entity\Tenant;
use App\Entity\UsdRate;
use App\Repository\TenantRepository;
use App\Repository\Contract\UsdRateRepositoryInterface;
use App\Repository\Contract\AppSettingRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Servicio de gestión de tasas USD con soporte para correcciones.
 *
 * POLÍTICA DE CORRECCIONES (ADR-0004):
 *   - Si ya existe una tasa para la fecha solicitada, NO se sobrescribe.
 *   - Se crea una NUEVA entidad UsdRate con:
 *       isCorrection = true
 *       supersedes = la tasa anterior (la última insertada para esa fecha)
 *   - findCurrent() y findEffectiveByDate() devuelven la última insertada
 *     (createdAt DESC, id DESC), que será la corrección más reciente.
 *   - Esto preserva historial completo y permite auditoría.
 *   - El Setting KEY_USD_RATE_CURRENT se actualiza SIEMPRE a la tasa vigente
 *     (incluyendo correcciones) para que SettingsService::getUsdRateCurrent()
 *     refleje lo que el usuario ve en pantalla.
 */
final class UsdRateService
{
    public function __construct(
        private readonly UsdRateRepositoryInterface $rateRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly TenantContext $tenantContext,
        private readonly SettingsService $settingsService,
        private readonly AppSettingRepositoryInterface $settingRepository,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Registra una nueva tasa o corrección para una fecha.
     *
     * @param \DateTimeImmutable $date     Fecha de la tasa (solo date, time se ignora)
     * @param string             $rate     Tasa > 0 (ej. "350.5000")
     * @param AppUser|null       $by       Usuario que registra (para auditoría)
     * @param string|null        $note     Nota opcional (fuente, observación)
     *
     * @return UsdRate La entidad creada (nueva o corrección)
     */
    public function setRate(
        \DateTimeImmutable $date,
        string $rate,
        ?AppUser $by = null,
        ?string $note = null
    ): UsdRate {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new \LogicException('TenantContext sin tenantId.');
        }

        // Normaliza a solo fecha (sin hora)
        $dateOnly = new \DateTimeImmutable($date->format('Y-m-d'));

        // Busca si ya existe tasa para esa fecha
        $existing = $this->rateRepository->findEffectiveByDate($tenantId, $dateOnly);

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            throw new \LogicException(sprintf('Tenant %d no encontrado.', $tenantId));
        }

        $usdRate = new UsdRate();
        $usdRate->setTenant($tenant);
        $usdRate->setRateDate($dateOnly);
        $usdRate->setRate($rate);
        $usdRate->setCreatedBy($by);
        $usdRate->setSourceNote($note);

        if ($existing !== null) {
            // ES CORRECCIÓN: encadena a la anterior
            $usdRate->setIsCorrection(true);
            $usdRate->setSupersedes($existing);
        }

        // Persistir la nueva tasa
        $this->entityManager->persist($usdRate);
        $this->entityManager->flush();

        // Actualizar setting KEY_USD_RATE_CURRENT a la nueva tasa (incluyendo correcciones)
        $setting = $this->settingRepository->findByKeyAndTenant(AppSetting::KEY_USD_RATE_CURRENT, $tenantId);
        if ($setting === null) {
            $setting = new AppSetting();
            $setting->setTenant($tenant);
            $setting->setSettingKey(AppSetting::KEY_USD_RATE_CURRENT);
            $this->entityManager->persist($setting);
        }
        $setting->setSettingValue($rate);
        $this->entityManager->flush();

        // Invalidar cache de SettingsService para que el próximo getUsdRateCurrent() lea el nuevo valor
        $this->settingsService->clearCache();

        return $usdRate;
    }

    /**
     * Tasa vigente actual (última insertada, considerando correcciones).
     */
    public function getCurrent(): ?UsdRate
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            return null;
        }
        return $this->rateRepository->findCurrent($tenantId);
    }

    /**
     * Tasa efectiva para una fecha dada.
     * Si hay correcciones en esa fecha, devuelve la última.
     */
    public function getEffectiveFor(\DateTimeImmutable $date): ?UsdRate
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            return null;
        }
        $dateOnly = new \DateTimeImmutable($date->format('Y-m-d'));
        return $this->rateRepository->findEffectiveByDate($tenantId, $dateOnly);
    }
}