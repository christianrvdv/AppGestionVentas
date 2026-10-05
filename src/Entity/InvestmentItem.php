<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvestmentItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvestmentItemRepository::class)]
#[ORM\Table(name: 'investment_item')]
#[ORM\Index(name: 'idx_item_tenant_investment', columns: ['tenant_id', 'investment_id'])]
#[ORM\Index(name: 'idx_item_tenant_product', columns: ['tenant_id', 'product_id'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Investment::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'investment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Investment $investment;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'investmentItems')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(name: 'unit_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitCost;

    #[ORM\Column(name: 'allocated_expense', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $allocatedExpense = '0.00';

    #[ORM\Column(name: 'real_unit_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $realUnitCost;

    #[ORM\Column(name: 'suggested_margin_pct', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $suggestedMarginPct;

    #[ORM\Column(name: 'suggested_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $suggestedPrice;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, SaleLine>
     */
    #[ORM\OneToMany(mappedBy: 'investmentItem', targetEntity: SaleLine::class)]
    private Collection $saleLines;

    /**
     * @var Collection<int, InventoryMovement>
     */
    #[ORM\OneToMany(mappedBy: 'investmentItem', targetEntity: InventoryMovement::class)]
    private Collection $inventoryMovements;

    public function __construct()
    {
        $this->saleLines = new ArrayCollection();
        $this->inventoryMovements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->unitCost = '0.00';
        $this->realUnitCost = '0.00';
        $this->suggestedMarginPct = '0.00';
        $this->suggestedPrice = '0.00';
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getInvestment(): Investment
    {
        return $this->investment;
    }

    public function setInvestment(Investment $investment): self
    {
        $this->investment = $investment;
        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitCost(): string
    {
        return $this->unitCost;
    }

    public function setUnitCost(string $unitCost): self
    {
        $this->unitCost = $unitCost;
        return $this;
    }

    public function getAllocatedExpense(): string
    {
        return $this->allocatedExpense;
    }

    public function setAllocatedExpense(string $allocatedExpense): self
    {
        $this->allocatedExpense = $allocatedExpense;
        return $this;
    }

    public function getRealUnitCost(): string
    {
        return $this->realUnitCost;
    }

    public function setRealUnitCost(string $realUnitCost): self
    {
        $this->realUnitCost = $realUnitCost;
        return $this;
    }

    public function getSuggestedMarginPct(): string
    {
        return $this->suggestedMarginPct;
    }

    public function setSuggestedMarginPct(string $suggestedMarginPct): self
    {
        $this->suggestedMarginPct = $suggestedMarginPct;
        return $this;
    }

    public function getSuggestedPrice(): string
    {
        return $this->suggestedPrice;
    }

    public function setSuggestedPrice(string $suggestedPrice): self
    {
        $this->suggestedPrice = $suggestedPrice;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, SaleLine>
     */
    public function getSaleLines(): Collection
    {
        return $this->saleLines;
    }

    public function addSaleLine(SaleLine $saleLine): self
    {
        if (!$this->saleLines->contains($saleLine)) {
            $this->saleLines->add($saleLine);
            $saleLine->setInvestmentItem($this);
        }
        return $this;
    }

    public function removeSaleLine(SaleLine $saleLine): self
    {
        $this->saleLines->removeElement($saleLine);
        return $this;
    }

    /**
     * @return Collection<int, InventoryMovement>
     */
    public function getInventoryMovements(): Collection
    {
        return $this->inventoryMovements;
    }

    public function addInventoryMovement(InventoryMovement $inventoryMovement): self
    {
        if (!$this->inventoryMovements->contains($inventoryMovement)) {
            $this->inventoryMovements->add($inventoryMovement);
            $inventoryMovement->setInvestmentItem($this);
        }
        return $this;
    }

    public function removeInventoryMovement(InventoryMovement $inventoryMovement): self
    {
        $this->inventoryMovements->removeElement($inventoryMovement);
        return $this;
    }

    /**
     * quantity_sold and quantity_remaining are NOT persisted as columns.
     * They are calculated via SUM(inventory_movement.quantity_delta) where:
     * - quantity_sold = ABS(SUM(quantity_delta) for TYPE_SALE)
     * - quantity_remaining = SUM(quantity_delta) for all movements
     * This avoids data duplication and ensures consistency with the ledger.
     */
}