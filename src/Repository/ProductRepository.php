<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Repository\Contract\ProductRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ProductRepositoryInterface::class)]
class ProductRepository extends ServiceEntityRepository implements ProductRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Product[]
     */
    public function findActiveByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('p.isActive = :active')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('active', true)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Product[]
     */
    public function findByNameOrSku(int $tenantId, string $term, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('(LOWER(p.name) LIKE :term OR LOWER(p.sku) LIKE :term)')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('term', '%' . mb_strtolower($term) . '%')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Ranking de productos más rentables en un período.
     * Por defecto EXCLUYE ventas anuladas: rankear con datos contaminados
     * produce decisiones de negocio erróneas.
     *
     * @return array<int, array{id: int, name: string, totalProfit: string}>
     */
    public function findMostProfitable(
        int                $tenantId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int                $limit = 10,
        bool               $includeVoided = false
    ): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.id AS id, p.name AS name, SUM(sl.grossProfitLine) AS totalProfit')
            ->innerJoin('p.investmentItems', 'ii')
            ->innerJoin('ii.saleLines', 'sl')
            ->innerJoin('sl.sale', 's')
            ->andWhere('p.tenant = :tenantId')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if (!$includeVoided) {
            $qb->andWhere('s.voidedAt IS NULL');
        }

        $rows = $qb
            ->groupBy('p.id')
            ->addGroupBy('p.name')
            ->orderBy('totalProfit', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): array => [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'totalProfit' => (string)($row['totalProfit'] ?? '0.00'),
            ],
            $rows
        );
    }
}
