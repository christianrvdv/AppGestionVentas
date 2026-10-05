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
#[ORM\HasLifecycleCallbacks]
class InventoryMovement
{
    public const TYPE_PURCHASE = 'PURCHASE';
    public const TYPE_SALE = 'SALE';
    public const TYPE_LOSS = 'LOSS';
    public const TYPE_RETURN = 'RETURN';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const REF_SALE_LINE = 'SALE_LINE';
    public const REF_MANUAL = 'MANUAL';
    public const REF_IMPORT = 'IMPORT';

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
     * Can be negative (outflows: SALE, LOSS) or positive (inflows: PURCHASE, RETURN, positive ADJUSTMENT).
     * The sign convention: positive = stock increase, negative = stock decrease.
     */
    #[ORM\Column(name: 'quantity_delta', type: Types::INTEGER)]
    private int $quantityDelta;

    /**
     * Snapshot of unit cost at time of movement.
     * Nullable because not all movement types have an associated cost (e.g., LOSS, ADJUSTMENT).
     */
    #[ORM\Column(name: 'unit_cost_snapshot', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $unitCostSnapshot = null;

    /**
     * Polymorphic reference to the source document.
     * NOT an ORM relationship because it can reference different entity types
     * (SaleLine, manual entry, import batch, etc.).
     * The combination of reference_type + reference_id identifies the source.
     */
    #[ORM\Column(name: 'reference_type', type: Types::STRING, length: 30, nullable: true)]
    private ?string $referenceType = null;

    #[ORM\Column(name: 'reference_id', type: Types::INTEGER, nullable: true)]
    private ?int $referenceId = null;

    #[ORM\Column(name: 'movement_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $movementDate;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getInvestmentItem(): InvestmentItem
    {
        return $this->investmentItem;
    }

    public function setInvestmentItem(InvestmentItem $investmentItem): self
    {
        $this->investmentItem = $investmentItem;
        return $this;
    }

    public function getMovementType(): string
    {
        return $this->movementType;
    }

    public function setMovementType(string $movementType): self
    {
        $this->movementType = $movementType;
        return $this;
    }

    public function getQuantityDelta(): int
    {
        return $this->quantityDelta;
    }

    public function setQuantityDelta(int $quantityDelta): self
    {
        $this->quantityDelta = $quantityDelta;
        return $this;
    }

    public function getUnitCostSnapshot(): ?string
    {
        return $this->unitCostSnapshot;
    }

    public function setUnitCostSnapshot(?string $unitCostSnapshot): self
    {
        $this->unitCostSnapshot = $unitCostSnapshot;
        return $this;
    }

    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }

    public function setReferenceType(?string $referenceType): self
    {
        $this->referenceType = $referenceType;
        return $this;
    }

    public function getReferenceId(): ?int
    {
        return $this->referenceId;
    }

    public function setReferenceId(?int $referenceId): self
    {
        $this->referenceId = $referenceId;
        return $this;
    }

    public function getMovementDate(): \DateTimeImmutable
    {
        return $this->movementDate;
    }

    public function setMovementDate(\DateTimeImmutable $movementDate): self
    {
        $this->movementDate = $movementDate;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * This is an append-only ledger: movements are never edited or deleted.
     * Corrections are made via new ADJUSTMENT movements.
     */
}
