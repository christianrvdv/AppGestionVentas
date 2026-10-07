<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InventoryMovementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryMovementRepository::class)]
#[ORM\Table(name: 'inventory_movement')]
#[ORM\Index(name: 'idx_movement_tenant_item', columns: ['tenant_id', 'investment_item_id'])]
#[ORM\Index(name: 'idx_movement_tenant_date', columns: ['tenant_id', 'movement_date'])]
#[ORM\Index(name: 'idx_movement_tenant_type', columns: ['tenant_id', 'movement_type'])]
#[ORM\Index(name: 'idx_movement_reference', columns: ['tenant_id', 'reference_type', 'reference_id', 'movement_type'])]
#[ORM\UniqueConstraint(name: 'uniq_movement_idempotency', columns: ['idempotency_key'])]
#[ORM\HasLifecycleCallbacks]
class InventoryMovement
{
    public const TYPE_PURCHASE = 'PURCHASE';
    public const TYPE_SALE = 'SALE';
    public const TYPE_LOSS = 'LOSS';
    public const TYPE_CUSTOMER_RETURN = 'CUSTOMER_RETURN';
    public const TYPE_PURCHASE_RETURN = 'PURCHASE_RETURN';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const REF_SALE_LINE = 'SALE_LINE';
    public const REF_INVESTMENT_ITEM = 'INVESTMENT_ITEM';
    public const REF_INVESTMENT = 'INVESTMENT';
    public const REF_MANUAL = 'MANUAL';
    public const REF_IMPORT = 'IMPORT';

    public const REASON_DAMAGE = 'DAMAGE';
    public const REASON_THEFT = 'THEFT';
    public const REASON_EXPIRY = 'EXPIRY';
    public const REASON_COUNT_DIFF = 'COUNT_DIFF';
    public const REASON_OTHER = 'OTHER';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: InvestmentItem::class, inversedBy: 'inventoryMovements')]
    #[ORM\JoinColumn(name: 'investment_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InvestmentItem $investmentItem;

    #[ORM\Column(name: 'movement_type', type: Types::STRING, length: 20)]
    private string $movementType;

    /**
     * Clasificación de la causa. Solo aplica a LOSS y ADJUSTMENT.
     * Null en SALE, PURCHASE, returns.
     */
    #[ORM\Column(name: 'reason_code', type: Types::STRING, length: 30, nullable: true)]
    private ?string $reasonCode = null;

    #[ORM\Column(name: 'quantity_delta', type: Types::INTEGER)]
    private int $quantityDelta;

    #[ORM\Column(name: 'unit_cost_snapshot', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $unitCostSnapshot = null;

    #[ORM\Column(name: 'current_unit_cost_snapshot', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $currentUnitCostSnapshot = null;

    #[ORM\Column(name: 'reference_type', type: Types::STRING, length: 30, nullable: true)]
    private ?string $referenceType = null;

    #[ORM\Column(name: 'reference_id', type: Types::INTEGER, nullable: true)]
    private ?int $referenceId = null;

    #[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 120)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'movement_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $movementDate;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->movementDate = new \DateTimeImmutable();
        $this->idempotencyKey = self::generateManualKey();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        if ($this->quantityDelta === 0) {
            throw new \LogicException('InventoryMovement.quantityDelta no puede ser 0.');
        }
        if ($this->movementType === self::TYPE_LOSS && $this->quantityDelta > 0) {
            throw new \LogicException('Un movimiento LOSS debe tener quantityDelta negativo.');
        }
    }

    public static function generateManualKey(): string
    {
        return 'manual:' . bin2hex(random_bytes(16));
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $t): self
    {
        $this->tenant = $t;
        return $this;
    }

    public function getInvestmentItem(): InvestmentItem
    {
        return $this->investmentItem;
    }

    public function setInvestmentItem(InvestmentItem $i): self
    {
        $this->investmentItem = $i;
        return $this;
    }

    public function getMovementType(): string
    {
        return $this->movementType;
    }

    public function setMovementType(string $t): self
    {
        $this->movementType = $t;
        return $this;
    }

    public function getReasonCode(): ?string
    {
        return $this->reasonCode;
    }

    public function setReasonCode(?string $r): self
    {
        $this->reasonCode = $r;
        return $this;
    }

    public function getQuantityDelta(): int
    {
        return $this->quantityDelta;
    }

    public function setQuantityDelta(int $q): self
    {
        $this->quantityDelta = $q;
        return $this;
    }

    public function getUnitCostSnapshot(): ?string
    {
        return $this->unitCostSnapshot;
    }

    public function setUnitCostSnapshot(?string $v): self
    {
        $this->unitCostSnapshot = $v;
        return $this;
    }

    public function getCurrentUnitCostSnapshot(): ?string
    {
        return $this->currentUnitCostSnapshot;
    }

    public function setCurrentUnitCostSnapshot(?string $v): self
    {
        $this->currentUnitCostSnapshot = $v;
        return $this;
    }

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function setReferenceType(?string $v): self
    {
        $this->referenceType = $v;
        return $this;
    }

    public function getReferenceId(): ?int
    {
        return $this->referenceId;
    }

    public function setReferenceId(?int $v): self
    {
        $this->referenceId = $v;
        return $this;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(string $v): self
    {
        $this->idempotencyKey = $v;
        return $this;
    }

    public function getMovementDate(): \DateTimeImmutable
    {
        return $this->movementDate;
    }

    public function setMovementDate(\DateTimeImmutable $d): self
    {
        $this->movementDate = $d;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $n): self
    {
        $this->notes = $n;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
