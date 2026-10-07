<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvestmentSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestmentSummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvestmentSummary::class);
    }

    public function findByInvestment(int $investmentId, int $tenantId): ?InvestmentSummary
    {
        return $this->createQueryBuilder('is')
            ->andWhere('is.investment = :investmentId')
            ->andWhere('is.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return InvestmentSummary[]
     */
    public function findTopRecovered(int $tenantId, int $limit = 10): array
    {
        return $this->createQueryBuilder('is')
            ->andWhere('is.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('is.recoveryPct', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Totales consolidados del tenant. Expone AMBOS modos de recuperación
     * para que el dashboard pueda compararlos sin recomputar nada.
     *
     * @return array{
     *   totalInvestment: string,
     *   totalInvestmentCurrent: string,
     *   totalRecovered: string,
     *   totalRecoveredPerProduct: string,
     *   totalRecoveredInvestmentFirst: string,
     *   totalPending: string,
     *   totalGrossProfit: string,
     *   totalProfit: string,
     *   totalProfitPerProduct: string,
     *   totalProfitInvestmentFirst: string
     * }
     */
    public function sumTotalsByTenant(int $tenantId): array
    {
        $row = $this->createQueryBuilder('is')
            ->select(
                'SUM(is.totalInvestment) AS totalInvestment',
                'SUM(is.totalInvestmentCurrent) AS totalInvestmentCurrent',
                'SUM(is.totalRecovered) AS totalRecovered',
                'SUM(is.totalRecoveredPerProduct) AS totalRecoveredPerProduct',
                'SUM(is.totalRecoveredInvestmentFirst) AS totalRecoveredInvestmentFirst',
                'SUM(is.totalPending) AS totalPending',
                'SUM(is.totalGrossProfit) AS totalGrossProfit',
                'SUM(is.totalProfit) AS totalProfit',
                'SUM(is.totalProfitPerProduct) AS totalProfitPerProduct',
                'SUM(is.totalProfitInvestmentFirst) AS totalProfitInvestmentFirst'
            )
            ->andWhere('is.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'totalInvestment' => (string)($row['totalInvestment'] ?? '0.00'),
            'totalInvestmentCurrent' => (string)($row['totalInvestmentCurrent'] ?? '0.00'),
            'totalRecovered' => (string)($row['totalRecovered'] ?? '0.00'),
            'totalRecoveredPerProduct' => (string)($row['totalRecoveredPerProduct'] ?? '0.00'),
            'totalRecoveredInvestmentFirst' => (string)($row['totalRecoveredInvestmentFirst'] ?? '0.00'),
            'totalPending' => (string)($row['totalPending'] ?? '0.00'),
            'totalGrossProfit' => (string)($row['totalGrossProfit'] ?? '0.00'),
            'totalProfit' => (string)($row['totalProfit'] ?? '0.00'),
            'totalProfitPerProduct' => (string)($row['totalProfitPerProduct'] ?? '0.00'),
            'totalProfitInvestmentFirst' => (string)($row['totalProfitInvestmentFirst'] ?? '0.00'),
        ];
    }
}
