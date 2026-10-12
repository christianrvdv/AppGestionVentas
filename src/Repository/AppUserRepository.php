<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AppUser;
use App\Repository\Contract\AppUserRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<AppUser>
 */
#[AsAlias(AppUserRepositoryInterface::class)]
class AppUserRepository extends ServiceEntityRepository implements AppUserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppUser::class);
    }

    public function findOneByEmail(string $email): ?AppUser
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * ÚNICO lookup sin filtro de tenant de todo el repositorio
     * (ADR-0015; ver AppUserRepositoryInterface).
     *
     * NO filtra por tenant a propósito: lo usa únicamente
     * AppUserProvider::refreshUser(), que recarga el usuario de la
     * sesión por id. Nunca se usa para leer datos de negocio: el
     * aislamiento entre tenants lo aportan el TenantFilter (pobulado
     * desde la sesión) y RequireTenantListener.
     *
     * Si aparece un caso "parecido", es un bug de diseño — abrir un ADR
     * antes de tocar esta firma. Ver AppUserRepositoryInterfaceTest.
     */
    public function findById(int $id): ?AppUser
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todos los usuarios del sistema (ADR-0016): visión global del
     * super_admin vía app:user:list. Lista identidades, no datos de
     * negocio; el filtro de tenant no aplica a app_user (no tiene
     * asociación `tenant`).
     *
     * @return AppUser[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Total de usuarios globales (panel admin, Fase 6.1). Como todo el
     * repositorio (ADR-0015), no filtra por tenant: app_user no tiene
     * asociacion `tenant`.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * QueryBuilder paginable del listado /admin/users (Fase 6.1).
     *
     * Fetch-join de membresías (la vista muestra a qué negocios pertenece
     * cada cuenta); KnpPaginator pagina con Doctrine Paginator, que
     * cuenta DISTINCT sobre la raíz, así que el total no se infla.
     *
     * Los tres filtros son opcionales y acumulables:
     *   - $q: email insensible a mayúsculas;
     *   - $tenantId: negocio EXPLÍCITO en un EXISTS (sin depender del
     *     SQLFilter, apagado para el super_admin);
     *   - $role: rol GLOBAL guardado en app_user.roles, resuelto con una
     *     pre-lookup DBAL (roles es jsonb y DQL no admite LIKE sobre
     *     jsonb; PostgreSQL-only por ADR-0011). El valor se valida contra
     *     una allow-list ANTES de llegar aquí (AdminController).
     */
    public function createPaginatedQueryBuilder(
        ?string $q = null,
        ?int $tenantId = null,
        ?string $role = null
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.memberships', 'm')
            ->addSelect('m')
            ->orderBy('u.email', 'ASC')
            ->addOrderBy('u.id', 'ASC');

        if (null !== $q && '' !== $q) {
            $qb->andWhere('LOWER(u.email) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($q).'%');
        }

        if (null !== $tenantId) {
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\TenantMembership mt WHERE mt.user = u AND mt.tenant = :tenantId)')
                ->setParameter('tenantId', $tenantId);
        }

        if (null !== $role) {
            $ids = $this->findIdsByGlobalRole($role);

            // Sin cuentas con ese rol, la IN vacía no es DQL válido: se
            // convierte en una condición que nunca se cumple.
            $qb->andWhere([] === $ids ? 'u.id = -1' : 'u.id IN (:roleIds)');

            if ([] !== $ids) {
                $qb->setParameter('roleIds', $ids);
            }
        }

        return $qb;
    }

    /**
     * Ids de las cuentas que declaran un rol GLOBAL en app_user.roles
     * (jsonb). Busca sobre `roles::text` con el rol ENTRE comillas dobles
     * ("ROLE_ADMIN"), para que "ROLE_SUPER_ADMIN" no haga match por
     * subcadena.
     *
     * @return list<int>
     */
    public function findIdsByGlobalRole(string $role): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM app_user WHERE roles::text LIKE :pattern',
            ['pattern' => '%"'.$role.'"%']
        );

        return array_map('\intval', $ids);
    }
}
