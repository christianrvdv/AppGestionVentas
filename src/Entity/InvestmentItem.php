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
    /*
     * INVARIANTES (verificadas por InvestmentItemCalculatorService):
     *   allocatedExpense  = Σ ExpenseAllocation.allocatedAmount del ítem
     *   realUnitCost      = (unitCost * quantity + allocatedExpense) / quantity
     *   realUnitCostUsd   = realUnitCost / usdRateSnapshot
     *   suggestedPrice    = realUnitCost * (1 + suggestedMarginPct / 100)
     *
     * [PHASE-0] currentRealUnitCost y currentSuggestedPrice DEBEN ser
     * inicializados por InvestmentItemCalculatorService al confirmar la
     * inversión, copiando realUnitCost y suggestedPrice respectivamente.
     * NO se inicializan en la entidad para mantener una única fuente de
     * verdad: el servicio.
     *
     * fixedPrice: precio fijado manualmente por el usuario. Independiente
     * de suggestedPrice. Puede ser null (sin fijar). SaleLine.unitPrice
     * sigue siendo libre y puede diferir de ambos por regateo.
     */

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    // [PHASE-0] Concurrencia optimista.
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

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
    private string $unitCost = '0.00';

    #[ORM\Column(name: 'unit_cost_usd', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $unitCostUsd = null;

    #[ORM\Column(name: 'allocated_expense', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $allocatedExpense = '0.00';

    #[ORM\Column(name: 'allocated_expense_usd', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $allocatedExpenseUsd = null;

    #[ORM\Column(name: 'real_unit_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $realUnitCost = '0.00';

    #[ORM\Column(name: 'real_unit_cost_usd', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $realUnitCostUsd = null;

    #[ORM\Column(name: 'current_real_unit_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $currentRealUnitCost = '0.00';

    #[ORM\Column(name: 'suggested_margin_pct', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $suggestedMarginPct = '0.00';

    #[ORM\Column(name: 'suggested_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $suggestedPrice = '0.00';

    #[ORM\Column(name: 'current_suggested_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $currentSuggestedPrice = '0.00';

    // [PHASE-0] Precio fijado manualmente por el usuario.
    // Nullable: null significa "no fijado aún, usar suggestedPrice como guía".
    #[ORM\Column(name: 'fixed_price', type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $fixedPrice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'last_revalued_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRevaluedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SaleLine> */
    #[ORM\OneToMany(mappedBy: 'investmentItem', targetEntity: SaleLine::class)]
    private Collection $saleLines;

    /** @var Collection<int, InventoryMovement> */
    #[ORM\OneToMany(mappedBy: 'investmentItem', targetEntity: InventoryMovement::class)]
    private Collection $inventoryMovements;

    /** @var Collection<int, ItemCostRevaluation> */
    #[ORM\OneToMany(mappedBy: 'investmentItem', targetEntity: ItemCostRevaluation::class)]
    private Collection $revaluations;

    public function __construct()
    {
        $this->saleLines = new ArrayCollection();
        $this->inventoryMovements = new ArrayCollection();
        $this->revaluations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        if ($this->quantity <= 0) {
            throw new \LogicException('InvestmentItem.quantity debe ser > 0.');
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        if ($this->quantity <= 0) {
            throw new \LogicException('InvestmentItem.quantity debe ser > 0.');
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
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

    public function getInvestment(): Investment
    {
        return $this->investment;
    }

    public function setInvestment(Investment $i): self
    {
        $this->investment = $i;
        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $p): self
    {
        $this->product = $p;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $q): self
    {
        $this->quantity = $q;
        return $this;
    }

    public function getUnitCost(): string
    {
        return $this->unitCost;
    }

    public function setUnitCost(string $v): self
    {
        $this->unitCost = $v;
        return $this;
    }

    public function getUnitCostUsd(): ?string
    {
        return $this->unitCostUsd;
    }

    public function setUnitCostUsd(?string $v): self
    {
        $this->unitCostUsd = $v;
        return $this;
    }

    public function getAllocatedExpense(): string
    {
        return $this->allocatedExpense;
    }

    public function setAllocatedExpense(string $v): self
    {
        $this->allocatedExpense = $v;
        return $this;
    }

    public function getAllocatedExpenseUsd(): ?string
    {
        return $this->allocatedExpenseUsd;
    }

    public function setAllocatedExpenseUsd(?string $v): self
    {
        $this->allocatedExpenseUsd = $v;
        return $this;
    }

    public function getRealUnitCost(): string
    {
        return $this->realUnitCost;
    }

    public function setRealUnitCost(string $v): self
    {
        $this->realUnitCost = $v;
        return $this;
    }

    public function getRealUnitCostUsd(): ?string
    {
        return $this->realUnitCostUsd;
    }

    public function setRealUnitCostUsd(?string $v): self
    {
        $this->realUnitCostUsd = $v;
        return $this;
    }

    public function getCurrentRealUnitCost(): string
    {
        return $this->currentRealUnitCost;
    }

    public function setCurrentRealUnitCost(string $v): self
    {
        $this->currentRealUnitCost = $v;
        return $this;
    }

    public function getSuggestedMarginPct(): string
    {
        return $this->suggestedMarginPct;
    }

    public function setSuggestedMarginPct(string $v): self
    {
        $this->suggestedMarginPct = $v;
        return $this;
    }

    public function getSuggestedPrice(): string
    {
        return $this->suggestedPrice;
    }

    public function setSuggestedPrice(string $v): self
    {
        $this->suggestedPrice = $v;
        return $this;
    }

    public function getCurrentSuggestedPrice(): string
    {
        return $this->currentSuggestedPrice;
    }

    public function setCurrentSuggestedPrice(string $v): self
    {
        $this->currentSuggestedPrice = $v;
        return $this;
    }

    // [PHASE-0] Precio fijado manual. Nullable.
    public function getFixedPrice(): ?string
    {
        return $this->fixedPrice;
    }

    public function setFixedPrice(?string $v): self
    {
        $this->fixedPrice = $v;
        return $this;
    }

    /**
     * Precio efectivo a mostrar al vendedor: el fijado si existe,
     * si no el sugerido actual. NO es precio de venta obligatorio.
     */
    public function getEffectivePrice(): string
    {
        return $this->fixedPrice ?? $this->currentSuggestedPrice;
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

    public function getLastRevaluedAt(): ?\DateTimeImmutable
    {
        return $this->lastRevaluedAt;
    }

    public function setLastRevaluedAt(?\DateTimeImmutable $v): self
    {
        $this->lastRevaluedAt = $v;
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

    /** @return Collection<int, SaleLine> */
    public function getSaleLines(): Collection
    {
        return $this->saleLines;
    }

    public function addSaleLine(SaleLine $l): self
    {
        if (!$this->saleLines->contains($l)) {
            $this->saleLines->add($l);
            $l->setInvestmentItem($this);
        }
        return $this;
    }

    public function removeSaleLine(SaleLine $l): self
    {
        $this->saleLines->removeElement($l);
        return $this;
    }

    /** @return Collection<int, InventoryMovement> */
    public function getInventoryMovements(): Collection
    {
        return $this->inventoryMovements;
    }

    public function addInventoryMovement(InventoryMovement $m): self
    {
        if (!$this->inventoryMovements->contains($m)) {
            $this->inventoryMovements->add($m);
            $m->setInvestmentItem($this);
        }
        return $this;
    }

    public function removeInventoryMovement(InventoryMovement $m): self
    {
        $this->inventoryMovements->removeElement($m);
        return $this;
    }

    /** @return Collection<int, ItemCostRevaluation> */
    public function getRevaluations(): Collection
    {
        return $this->revaluations;
    }

    public function addRevaluation(ItemCostRevaluation $r): self
    {
        if (!$this->revaluations->contains($r)) {
            $this->revaluations->add($r);
            $r->setInvestmentItem($this);
        }
        return $this;
    }

    public function removeRevaluation(ItemCostRevaluation $r): self
    {
        $this->revaluations->removeElement($r);
        return $this;
    }
}
