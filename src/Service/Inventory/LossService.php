<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\InventoryMovement;
use App\Entity\InvestmentItem;
use App\Exception\Domain\InsufficientStockException;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Service\Investment\InvestmentSummaryService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registro de mermas (pérdidas de inventario).
 *
 * Crea InventoryMovement tipo LOSS con snapshots de costos.
 * Clave idempotente: InventoryMovement::generateLossKey($itemId, $uuid)
 * Recalcula InvestmentSummary.
 */
final class LossService
{
    /** Códigos de motivo válidos. */
    public const REASON_DAMAGE = InventoryMovement::REASON_DAMAGE;
    public const REASON_THEFT = InventoryMovement::REASON_THEFT;
    public const REASON_EXPIRY = InventoryMovement::REASON_EXPIRY;
    public const REASON_COUNT_DIFF = InventoryMovement::REASON_COUNT_DIFF;
    public const REASON_OTHER = InventoryMovement::REASON_OTHER;

    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movementRepository,
        private readonly InvestmentSummaryService $summaryService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Registra una merma.
     *
     * @param InvestmentItem       $item     Ítem afectado
     * @param int                  $quantity Cantidad perdida (> 0)
     * @param string               $reason   Código de motivo (DAMAGE, THEFT, EXPIRY, COUNT_DIFF, OTHER)
     * @param \DateTimeImmutable   $date     Fecha de la merma
     * @param string|null          $notes    Notas opcionales
     *
     * @return InventoryMovement Movimiento creado (listo para persistir)
     *
     * @throws InsufficientStockException Si stock insuficiente
     * @throws \InvalidArgumentException  Si reason inválido o quantity <= 0
     */
    public function register(
        InvestmentItem $item,
        int $quantity,
        string $reason,
        \DateTimeImmutable $date,
        ?string $notes = null
    ): InventoryMovement {
        $validReasons = [
            self::REASON_DAMAGE,
            self::REASON_THEFT,
            self::REASON_EXPIRY,
            self::REASON_COUNT_DIFF,
            self::REASON_OTHER,
        ];
        if (!in_array($reason, $validReasons, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Motivo inválido: %s. Válidos: %s',
                $reason,
                implode(', ', $validReasons)
            ));
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('quantity debe ser > 0');
        }

        $tenantId = $item->getTenant()->getId();
        $itemId = $item->getId();

        // Validar stock suficiente
        if (!$this->movementRepository->hasEnoughStock($itemId, $tenantId, $quantity)) {
            $available = $this->movementRepository->getQuantityDeltaSum($itemId, $tenantId);
            throw InsufficientStockException::forItem($itemId, $quantity, $available);
        }

        // Crear movimiento LOSS
        $movement = new InventoryMovement();
        $movement->setTenant($item->getTenant());
        $movement->setInvestmentItem($item);
        $movement->setMovementType(InventoryMovement::TYPE_LOSS);
        $movement->setReasonCode($reason);
        $movement->setQuantityDelta(-$quantity); // Negativo: pérdida
        $movement->setUnitCostSnapshot($item->getRealUnitCost());
        $movement->setCurrentUnitCostSnapshot($item->getCurrentRealUnitCost());
        $movement->setReferenceType(InventoryMovement::REF_INVESTMENT_ITEM);
        $movement->setReferenceId($itemId);
        $movement->setIdempotencyKey(InventoryMovement::generateLossKey($itemId, bin2hex(random_bytes(8))));
        $movement->setMovementDate($date);
        $movement->setNotes($notes);

        $this->entityManager->persist($movement);
        $item->addInventoryMovement($movement);

        // Recalcular summary de la inversión
        $investment = $item->getInvestment();
        $this->summaryService->recompute($investment);

        return $movement;
    }
}