<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExpenseAllocation;
use App\Repository\Contract\ExpenseAllocationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ExpenseAllocationRepositoryInterface::class)]
class ExpenseAllocationRepository extends ServiceEntityRepository implements ExpenseAllocationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseAllocation::class);
    }

    /**
     * @return ExpenseAllocation[]
     */
    public function findByInvestmentItem(int $itemId, int $tenantId): array
    {
        return $this->createQueryBuilder('ea')
            ->andWhere('ea.investmentItem = :itemId')
            ->andWhere('ea.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ExpenseAllocation[]
     */
    public function findByExpense(int $expenseId, int $tenantId): array
    {
        return $this->createQueryBuilder('ea')
            ->andWhere('ea.investmentExpense = :expenseId')
            ->andWhere('ea.tenant = :tenantId')
            ->setParameter('expenseId', $expenseId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getResult();
    }

    public function sumByExpense(int $expenseId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('ea')
            ->select('SUM(ea.allocatedAmount) AS total')
            ->andWhere('ea.investmentExpense = :expenseId')
            ->andWhere('ea.tenant = :tenantId')
            ->setParameter('expenseId', $expenseId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function sumByItem(int $itemId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('ea')
            ->select('SUM(ea.allocatedAmount) AS total')
            ->andWhere('ea.investmentItem = :itemId')
            ->andWhere('ea.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (string) ($result['total'] ?? '0.00');
    }

    public function deleteByExpense(int $expenseId, int $tenantId): int
    {
        return $this->createQueryBuilder('ea')
            ->delete()
            ->andWhere('ea.investmentExpense = :expenseId')
            ->andWhere('ea.tenant = :tenantId')
            ->setParameter('expenseId', $expenseId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->execute();
    }

    /**
     * @return ExpenseAllocation[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array
    {
        return $this->createQueryBuilder('ea')
            ->innerJoin('ea.investmentExpense', 'e')
            ->andWhere('e.investment = :investmentId')
            ->andWhere('ea.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('ea.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function deleteByItem(int $itemId, int $tenantId): int
    {
        return $this->createQueryBuilder('ea')
            ->delete()
            ->andWhere('ea.investmentItem = :itemId')
            ->andWhere('ea.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->execute();
    }
}
