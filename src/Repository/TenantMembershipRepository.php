<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\TenantFilterGuard;
use App\Entity\AppUser;
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
}
