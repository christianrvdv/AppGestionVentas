<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?Customer
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Customer[]
     */
    public function findActiveByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.isActive = :active')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Customer[]
     */
    public function findByNameOrPhone(int $tenantId, string $term, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('(LOWER(c.name) LIKE :term OR c.phone LIKE :term)')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByPhone(int $tenantId, string $phone): ?Customer
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.phone = :phone')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('phone', $phone)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByEmail(int $tenantId, string $email): ?Customer
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.email = :email')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countActiveByTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.tenant = :tenantId')
            ->andWhere('c.isActive = :active')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
