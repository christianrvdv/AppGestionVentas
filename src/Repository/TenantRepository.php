<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Repository\Contract\TenantRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TenantRepositoryInterface::class)]
class TenantRepository extends ServiceEntityRepository implements TenantRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Tenant[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}