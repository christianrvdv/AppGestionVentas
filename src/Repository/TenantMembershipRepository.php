<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\TenantFilterGuard;
use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends ServiceEntityRepository<TenantMembership>
 */
#[AsAlias(TenantMembershipRepositoryInterface::class)]
class TenantMembershipRepository extends ServiceEntityRepository implements TenantMembershipRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly TenantFilterGuard $filterGuard,
    ) {
        parent::__construct($registry, TenantMembership::class);
    }

    public function findByUser(AppUser $user): array
    {
        return $this->filterGuard->withoutTenantFilter(function () use ($user): array {
            return $this->createQueryBuilder('m')
                ->andWhere('m.user = :user')
                ->setParameter('user', $user)
                ->orderBy('m.isDefault', 'DESC')
                ->addOrderBy('m.joinedAt', 'ASC')
                ->getQuery()
                ->getResult();
        });
    }

    public function findByIdAndUser(int $membershipId, int $userId): ?TenantMembership
    {
        return $this->filterGuard->withoutTenantFilter(function () use ($membershipId, $userId): ?TenantMembership {
            return $this->createQueryBuilder('m')
                ->andWhere('m.id = :id')
                ->andWhere('m.user = :userId')
                ->setParameter('id', $membershipId)
                ->setParameter('userId', $userId)
                ->getQuery()
                ->getOneOrNullResult();
        });
    }

    /**
     * Todas las membresías de un negocio concreto, con el usuario
     * hidratado (ADR-0016; visión global del super_admin vía
     * app:user:list --tenant).
     *
     * Igual que findByUser, corre con el filtro deshabilitado: el filtro
     * delimita datos de negocio por el tenant de la sesión, y esta
     * consulta es justamente la que el panel de administración usa para
     * mirar UN tenant concreto (que puede no ser el de la sesión).
     *
     * @return TenantMembership[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->filterGuard->withoutTenantFilter(function () use ($tenant): array {
            return $this->createQueryBuilder('m')
                ->andWhere('m.tenant = :tenant')
                ->setParameter('tenant', $tenant)
                ->leftJoin('m.user', 'u')
                ->addSelect('u')
                ->orderBy('m.isDefault', 'DESC')
                ->addOrderBy('m.joinedAt', 'ASC')
                ->getQuery()
                ->getResult();
        });
    }

    /**
     * Total de membresías del sistema (panel admin, Fase 6.1).
     *
     * @return int
     */
    public function countAll(): int
    {
        return $this->filterGuard->withoutTenantFilter(function (): int {
            return (int) $this->createQueryBuilder('m')
                ->select('COUNT(m.id)')
                ->getQuery()
                ->getSingleScalarResult();
        });
    }

    /**
     * Membresias (== usuarios, por la unique user+tenant) de una tanda
     * de tenants: los contadores por fila del listado /admin/tenants
     * (Fase 6.1). Un solo query agrupado, sin N+1.
     *
     * @param list<int> $tenantIds
     *
     * @return array<int, int> tenantId => cantidad (solo los pedidos; los
     *                          que no tengan filas no aparecen)
     */
    public function countByTenants(array $tenantIds): array
    {
        if ([] === $tenantIds) {
            return [];
        }

        return $this->filterGuard->withoutTenantFilter(function () use ($tenantIds): array {
            $rows = $this->createQueryBuilder('m')
                ->select('IDENTITY(m.tenant) AS tenantId', 'COUNT(m.id) AS total')
                ->andWhere('m.tenant IN (:tenantIds)')
                ->setParameter('tenantIds', $tenantIds)
                ->groupBy('m.tenant')
                ->getQuery()
                ->getArrayResult();

            $counts = [];
            foreach ($rows as $row) {
                $counts[(int) $row['tenantId']] = (int) $row['total'];
            }

            return $counts;
        });
    }
}
