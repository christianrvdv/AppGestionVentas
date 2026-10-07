<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSetting;
use App\Repository\Contract\AppSettingRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AppSettingRepositoryInterface::class)]
class AppSettingRepository extends ServiceEntityRepository implements AppSettingRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    public function findValueByKey(int $tenantId, string $key): ?string
    {
        try {
            $value = $this->createQueryBuilder('s')
                ->select('s.settingValue')
                ->andWhere('s.tenant = :tenantId')
                ->andWhere('s.settingKey = :key')
                ->setParameter('tenantId', $tenantId)
                ->setParameter('key', $key)
                ->getQuery()
                ->getSingleScalarResult();
        } catch (NoResultException) {
            return null;
        }

        return $value;
    }

    public function findSetting(int $tenantId, string $key): ?AppSetting
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.settingKey = :key')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<string, string>
     */
    public function findAllKeyedByKey(int $tenantId): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.settingKey AS k, s.settingValue AS v')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getArrayResult();

        $keyed = [];
        foreach ($results as $row) {
            $keyed[$row['k']] = $row['v'] ?? '';
        }
        return $keyed;
    }

    public function getString(int $tenantId, string $key, ?string $default = null): ?string
    {
        $value = $this->findValueByKey($tenantId, $key);
        return $value ?? $default;
    }

    public function getInt(int $tenantId, string $key, ?int $default = null): ?int
    {
        $value = $this->findValueByKey($tenantId, $key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        return (int)$value;
    }

    public function getDecimal(int $tenantId, string $key, ?string $default = null): ?string
    {
        $value = $this->findValueByKey($tenantId, $key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        return $value;
    }

    public function getBool(int $tenantId, string $key, ?bool $default = null): ?bool
    {
        $value = $this->findValueByKey($tenantId, $key);
        if ($value === null) {
            return $default;
        }
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => $default,
        };
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    public function getEnum(
        int          $tenantId,
        string       $key,
        string       $enumClass,
        ?\BackedEnum $default = null
    ): ?\BackedEnum
    {
        $value = $this->findValueByKey($tenantId, $key);
        if ($value === null) {
            return $default;
        }
        try {
            return $enumClass::from($value);
        } catch (\ValueError) {
            return $default;
        }
    }
}
