<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSetting;
use App\Entity\Investment;
use App\Repository\Contract\AppSettingRepositoryInterface;
use InvalidArgumentException;

/**
 * Acceso tipado y cacheado a settings del tenant activo.
 *
 * Carga perezosa: la primera llamada a cualquier getter dispara una única
 * query `SELECT setting_key, setting_value FROM app_setting WHERE tenant_id = ?`.
 * Las llamadas subsiguientes en el mismo request usan el array en memoria.
 *
 * Invariante: NUNCA golpea la BD más de una vez por request POR TENANT.
 * Con workers persistentes (RoadRunner/FrankenPHP/Swoole), el cache se invalida
 * automáticamente cuando cambia el tenant activo (C.5).
 */
final class SettingsService
{
    /** @var array<string, string|null> */
    private array $cache = [];

    private bool $loaded = false;
    private ?int $cachedForTenantId = null;

    public function __construct(
        private readonly AppSettingRepositoryInterface $settingRepository,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Carga todos los settings del tenant activo en memoria.
     * Idempotente: si ya cargó para el MISMO tenant, no hace nada.
     */
    private function ensureLoaded(): void
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new \LogicException('TenantContext no tiene tenantId asignado. ¿Falta TenantFilterListener?');
        }

        // Si ya cargamos para este tenant, no recargar
        if ($this->loaded && $this->cachedForTenantId === $tenantId) {
            return;
        }

        $settings = $this->settingRepository->findAllKeyedByKey($tenantId);
        $this->cache = [];
        foreach ($settings as $key => $value) {
            $this->cache[$key] = $value;
        }
        $this->loaded = true;
        $this->cachedForTenantId = $tenantId;
    }

    /**
     * Obtiene un valor raw del cache (carga si necesario).
     */
    private function getRaw(string $key): ?string
    {
        $this->ensureLoaded();
        return $this->cache[$key] ?? null;
    }

    /**
     * Margen por defecto para precio sugerido (porcentaje, ej. "40.00").
     * Default hardcodeado: 40% si no hay setting.
     */
    public function getDefaultMarginPct(): string
    {
        $value = $this->getRaw(AppSetting::KEY_DEFAULT_MARGIN_PCT);
        return $value !== null && $value !== '' ? $value : '40.00';
    }

    /**
     * Moneda base del tenant (ej. "ARS", "USD", "COP").
     * Default hardcodeado: "ARS" si no hay setting.
     */
    public function getBaseCurrency(): string
    {
        $value = $this->getRaw(AppSetting::KEY_BASE_CURRENCY);
        return $value !== null && $value !== '' ? $value : 'ARS';
    }

    /**
     * Si el control de tasa USD está habilitado para el tenant.
     * Default: false.
     */
    public function isUsdControlEnabled(): bool
    {
        $value = $this->getRaw(AppSetting::KEY_USD_CONTROL_ENABLED);
        return $value === 'true' || $value === '1';
    }

    /**
     * Modo de recuperación por defecto para nuevas inversiones.
     * Valores válidos: Investment::RECOVERY_PER_PRODUCT | Investment::RECOVERY_INVESTMENT_FIRST
     * Default: PER_PRODUCT.
     */
    public function getRecoveryModeDefault(): string
    {
        $value = $this->getRaw(AppSetting::KEY_RECOVERY_MODE);
        if ($value !== null && $value !== '') {
            return $value;
        }
        return Investment::RECOVERY_PER_PRODUCT;
    }

    /**
     * Tasa USD actual guardada en settings (snapshot manual del usuario).
     * Null si no configurada.
     * Distinto de UsdRate::getCurrent() que es la tabla histórica.
     */
    public function getUsdRateCurrent(): ?string
    {
        $value = $this->getRaw(AppSetting::KEY_USD_RATE_CURRENT);
        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * Escala (decimales) para la moneda base del tenant.
     * Default: 2.
     * Permite monedas sin decimales (ej. JPY -> 0) o con más (ej. BHD -> 3).
     */
    public function getCurrencyScale(): int
    {
        $value = $this->getRaw('currency_scale');
        if ($value !== null && $value !== '' && ctype_digit($value)) {
            $scale = (int)$value;
            if ($scale >= 0 && $scale <= 6) {
                return $scale;
            }
        }
        return 2;
    }

    /**
     * Invalida el cache (útil para tests o comandos CLI que cambian settings).
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->loaded = false;
        $this->cachedForTenantId = null;
    }
}