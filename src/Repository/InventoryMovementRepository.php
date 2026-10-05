<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InventoryMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, \App\Entity\InventoryMovement::class);
    }

    public function getQuantityDeltaSum(int $investmentItemId): int
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(im.quantityDelta) as total')
            ->andWhere('im.investmentItem = :itemId')
            ->setParameter('itemId', $investmentItemId)
            ->getQuery()
            ->getOneOrNullResult();

        return (int) ($result['total'] ?? 0);
    }

    /**
     * @return array<int, array{in: int, out: int, balance: int}>
     */
    public function getStockByItem(int $tenantId): array
    {
        $results = $this->createQueryBuilder('im')
            ->select('IDENTITY(im.investmentItem) as itemId, im.movementType, SUM(im.quantityDelta) as delta')
            ->andWhere('im.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->groupBy('im.investmentItem, im.movementType')
            ->getQuery()
            ->getResult();

        $stock = [];
        foreach ($results as $row) {
            $itemId = $row['itemId'];
            $type = $row['movementType'];
            $delta = (int) $row['delta'];

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

    public function getSoldQuantity(int $investmentItemId): int
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(ABS(im.quantityDelta)) as total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.movementType = :type')
            ->setParameter('itemId', $investmentItemId)
            ->setParameter('type', \App\Entity\InventoryMovement::TYPE_SALE)
            ->getQuery()
            ->getOneOrNullResult();

        return (int) ($result['total'] ?? 0);
    }

    public function getLostQuantity(int $investmentItemId): int
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(ABS(im.quantityDelta)) as total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.movementType = :type')
            ->setParameter('itemId', $investmentItemId)
            ->setParameter('type', \App\Entity\InventoryMovement::TYPE_LOSS)
            ->getQuery()
            ->getOneOrNullResult();

        return (int) ($result['total'] ?? 0);
    }

    public function getLostCapital(int $investmentItemId): string
    {
        $result = $this->createQueryBuilder('im')
            ->select('SUM(ABS(im.quantityDelta) * im.unitCostSnapshot) as total')
            ->andWhere('im.investmentItem = :itemId')
            ->andWhere('im.movementType = :type')
            ->andWhere('im.unitCostSnapshot IS NOT NULL')
            ->setParameter('itemId', $investmentItemId)
            ->setParameter('type', \App\Entity\InventoryMovement::TYPE_LOSS)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['total'] ?? '0.00';
    }
}