<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AppSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppSetting::class);
    }

    public function findValueByKey(int $tenantId, string $key): ?string
    {
        $result = $this->createQueryBuilder('s')
            ->select('s.settingValue')
            ->andWhere('s.tenant = :tenantId')
            ->andWhere('s.settingKey = :key')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['settingValue'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function findAllKeyedByKey(int $tenantId): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.settingKey, s.settingValue')
            ->andWhere('s.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();

        $keyed = [];
        foreach ($results as $row) {
            $keyed[$row['settingKey']] = $row['settingValue'] ?? '';
        }
        return $keyed;
    }
}