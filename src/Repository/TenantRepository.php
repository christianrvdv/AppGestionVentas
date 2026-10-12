<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Repository\Contract\TenantRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
#[AsAlias(TenantRepositoryInterface::class)]
class TenantRepository extends ServiceEntityRepository implements TenantRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findById(int $id): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
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

    /**
     * Total de tenants ACTIVOS (panel admin, Fase 6.1). Conteo global:
     * su único consumidor es /admin, que exige ROLE_SUPER_ADMIN.
     */
    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Todos los tenants ordenados por nombre: el desplegable de filtro
     * "por tenant" de /admin/users. Igual que countActive, es consulta
     * GLOBAL del panel (Fase 6.1).
     *
     * @return Tenant[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * QueryBuilder paginable del listado /admin/tenants (Fase 6.1).
     *
     * Orden estable (name ASC, id DESC como desempate) para que no se
     * repitan ni se salten filas entre páginas. Los filtros son
     * opcionales: estado exacto (activo/inactivo) y búsqueda
     * insensible a mayúsculas sobre nombre o slug. Es consulta GLOBAL:
     * sin filtro de tenant, que es justamente lo que hace el panel.
     */
    public function createPaginatedQueryBuilder(?bool $isActive = null, ?string $q = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.name', 'ASC')
            ->addOrderBy('t.id', 'DESC');

        if (null !== $isActive) {
            $qb->andWhere('t.isActive = :isActive')
                ->setParameter('isActive', $isActive);
        }

        if (null !== $q && '' !== $q) {
            $qb->andWhere('(LOWER(t.name) LIKE :q OR LOWER(t.slug) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        return $qb;
    }
}
