<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InventoryMovement;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(InventoryMovementRepositoryInterface::class)]
class InventoryMovementRepository extends ServiceEntityRepository implements InventoryMovementRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryMovement::class);
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?InventoryMovement
    {
        return $this->createQueryBuilder('im')
            ->andWhere('im.id = :id')
            ->andWhere('im.tenant = :tenantId')
            ->setParameter('id', $id)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function getQuantityDeltaSum(int $itemId, int $tenantId): int
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(im.quantityDelta) AS total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();

        return (int)($result['total'] ?? 0);
    }

    /**
     * @return array<int, array{in: int, out: int, balance: int}>
     */
    public function getStockByItem(int $tenantId): array
    {
        $results = $this->createQueryBuilder('im')
            ->select('IDENTITY(im.investmentItem) AS itemId, im.movementType AS movementType, SUM(im.quantityDelta) AS delta')
            ->andWhere('im.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->groupBy('im.investmentItem')
            ->addGroupBy('im.movementType')
            ->getQuery()
            ->getArrayResult();

        $stock = [];
        foreach ($results as $row) {
            $itemId = (int)$row['itemId'];
            $delta = (int)$row['delta'];

            if (!isset($stock[$itemId])) {
                $stock[$itemId] = ['in' => 0, 'out' => 0, 'balance' => 0];
            }

            if ($delta > 0) {
                $stock[$itemId]['in'] += $delta;
            } else {
                $stock[$itemId]['out'] += abs($delta);
            }
            $stock[$itemId]['balance'] += $delta;
        }

        return $stock;
    }

    public function getSoldQuantity(int $itemId, int $tenantId): int
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(ABS(im.quantityDelta)) AS total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementType = :type')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', InventoryMovement::TYPE_SALE)
            ->getQuery()
            ->getOneOrNullResult();

        return (int)($result['total'] ?? 0);
    }

    public function getLostQuantity(int $itemId, int $tenantId): int
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(ABS(im.quantityDelta)) AS total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementType = :type')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', InventoryMovement::TYPE_LOSS)
            ->getQuery()
            ->getOneOrNullResult();

        return (int)($result['total'] ?? 0);
    }

    public function getLostQuantityByInvestment(int $investmentId, int $tenantId): int
    {
        $result = $this->createQueryBuilder('im')
            ->innerJoin('im.investmentItem', 'ii')
            ->select('SUM(ABS(im.quantityDelta)) AS total')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementType = :type')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', InventoryMovement::TYPE_LOSS)
            ->getQuery()
            ->getOneOrNullResult();

        return (int)($result['total'] ?? 0);
    }

    /**
     * Capital perdido usando el costo histórico.
     */
    public function getLostCapital(int $itemId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(ABS(im.quantityDelta) * im.unitCostSnapshot) AS total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementType = :type')
            ->andWhere('im.unitCostSnapshot IS NOT NULL')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', InventoryMovement::TYPE_LOSS)
            ->getQuery()
            ->getOneOrNullResult();

        return (string)($result['total'] ?? '0.00');
    }

    /**
     * Capital perdido usando el costo revaluado (a tasa actual).
     * Si no hay revaluación se cae al histórico.
     */
    public function getLostCapitalCurrent(int $itemId, int $tenantId): string
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(ABS(im.quantityDelta) * COALESCE(im.currentUnitCostSnapshot, im.unitCostSnapshot)) AS total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementType = :type')
            ->andWhere('im.unitCostSnapshot IS NOT NULL')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', InventoryMovement::TYPE_LOSS)
            ->getQuery()
            ->getOneOrNullResult();

        return (string)($result['total'] ?? '0.00');
    }

    /**
     * @return InventoryMovement[]
     */
    public function findByItem(int $itemId, int $tenantId): array
    {
        return $this->createQueryBuilder('im')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.tenant = :tenantId')
            ->setParameter('itemId', $itemId)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('im.movementDate', 'ASC')
            ->addOrderBy('im.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsForReference(
        int    $tenantId,
        string $referenceType,
        int    $referenceId,
        string $movementType
    ): bool
    {
        $count = (int)$this->createQueryBuilder('im')
            ->select('COUNT(im.id)')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.referenceType = :referenceType')
            ->andWhere('im.referenceId = :referenceId')
            ->andWhere('im.movementType = :movementType')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('referenceType', $referenceType)
            ->setParameter('referenceId', $referenceId)
            ->setParameter('movementType', $movementType)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function findByIdempotencyKey(int $tenantId, string $idempotencyKey): ?InventoryMovement
    {
        return $this->createQueryBuilder('im')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.idempotencyKey = :key')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('key', $idempotencyKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasEnoughStock(int $itemId, int $tenantId, int $quantity): bool
    {
        return $this->getQuantityDeltaSum($itemId, $tenantId) >= $quantity;
    }

    /**
     * @return InventoryMovement[]
     */
    public function findByDateRange(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('im')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementDate BETWEEN :from AND :to')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('im.movementDate', 'ASC')
            ->addOrderBy('im.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return InventoryMovement[]
     */
    public function findByType(int $tenantId, string $movementType): array
    {
        return $this->createQueryBuilder('im')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementType = :type')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', $movementType)
            ->orderBy('im.movementDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{in: int, out: int, balance: int}>
     */
    public function getStockByInvestment(int $investmentId, int $tenantId): array
    {
        $results = $this->createQueryBuilder('im')
            ->innerJoin('im.investmentItem', 'ii')
            ->select('IDENTITY(im.investmentItem) AS itemId, im.movementType AS movementType, SUM(im.quantityDelta) AS delta')
            ->andWhere('ii.investment = :investmentId')
            ->andWhere('im.tenant = :tenantId')
            ->setParameter('investmentId', $investmentId)
            ->setParameter('tenantId', $tenantId)
            ->groupBy('im.investmentItem')
            ->addGroupBy('im.movementType')
            ->getQuery()
            ->getArrayResult();

        $stock = [];
        foreach ($results as $row) {
            $itemId = (int)$row['itemId'];
            $delta = (int)$row['delta'];

            if (!isset($stock[$itemId])) {
                $stock[$itemId] = ['in' => 0, 'out' => 0, 'balance' => 0];
            }

            if ($delta > 0) {
                $stock[$itemId]['in'] += $delta;
            } else {
                $stock[$itemId]['out'] += abs($delta);
            }
            $stock[$itemId]['balance'] += $delta;
        }

        return $stock;
    }

    public function countLossesWithoutSnapshot(int $tenantId): int
    {
        return (int)$this->createQueryBuilder('im')
            ->select('COUNT(im.id)')
            ->andWhere('im.tenant = :tenantId')
            ->andWhere('im.movementType = :type')
            ->andWhere('im.unitCostSnapshot IS NULL')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('type', InventoryMovement::TYPE_LOSS)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
