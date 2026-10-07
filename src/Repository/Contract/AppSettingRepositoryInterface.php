<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\AppSetting;

/**
 * Puerto de persistencia para los ajustes por clave/valor de un tenant.
 * Implementación Doctrine: App\Repository\AppSettingRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface AppSettingRepositoryInterface
{
    public function findValueByKey(int $tenantId, string $key): ?string;

    public function findSetting(int $tenantId, string $key): ?AppSetting;

    /**
     * @return array<string, string>
     */
    public function findAllKeyedByKey(int $tenantId): array;

    public function getString(int $tenantId, string $key, ?string $default = null): ?string;

    public function getInt(int $tenantId, string $key, ?int $default = null): ?int;

    public function getDecimal(int $tenantId, string $key, ?string $default = null): ?string;

    public function getBool(int $tenantId, string $key, ?bool $default = null): ?bool;

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    public function getEnum(
        int          $tenantId,
        string       $key,
        string       $enumClass,
        ?\BackedEnum $default = null
    ): ?\BackedEnum;
}
