<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SaleLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SaleLineRepository::class)]
#[ORM\Table(name: 'sale_line')]
#[ORM\Index(name: 'idx_saleline_tenant_sale', columns: ['tenant_id', 'sale_id'])]
#[ORM\Index(name: 'idx_saleline_tenant_item', columns: ['tenant_id', 'investment_item_id'])]
#[ORM\HasLifecycleCallbacks]
class SaleLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Sale::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'sale_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Sale $sale;

    #[ORM\ManyToOne(targetEntity: InvestmentItem::class, inversedBy: 'saleLines')]
    #[ORM\JoinColumn(name: 'investment_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InvestmentItem $investmentItem;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'total_line', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalLine;

    /**
     * Snapshot of real_unit_cost at time of sale - immutable after creation.
     * Stored because InvestmentItem.real_unit_cost may change (e.g., expense reallocation)
     * but the sale's cost basis must remain fixed for accurate profit calculation.
     */
    #[ORM\Column(name: 'real_unit_cost_snapshot', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $realUnitCostSnapshot;

    /**
     * Cost recovered on this line = quantity * real_unit_cost_snapshot.
     * Stored as snapshot to preserve historical accuracy even if underlying data changes.
     */
    #[ORM\Column(name: 'cost_recovered', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $costRecovered;

    /**
     * Profit on this line = total_line - cost_recovered.
     * Stored as snapshot for consistent reporting and audit trail.
     */
    #[ORM\Column(name: 'profit_line', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $profitLine;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->unitPrice = '0.00';
        $this->totalLine = '0.00';
        $this->realUnitCostSnapshot = '0.00';
        $this->costRecovered = '0.00';
        $this->profitLine = '0.00';
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

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function setSale(Sale $sale): self
    {
        $this->sale = $sale;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): self
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getTotalLine(): string
    {
        return $this->totalLine;
    }

    public function setTotalLine(string $totalLine): self
    {
        $this->totalLine = $totalLine;
        return $this;
    }

    public function getRealUnitCostSnapshot(): string
    {
        return $this->realUnitCostSnapshot;
    }

    public function setRealUnitCostSnapshot(string $realUnitCostSnapshot): self
    {
        $this->realUnitCostSnapshot = $realUnitCostSnapshot;
        return $this;
    }

    public function getCostRecovered(): string
    {
        return $this->costRecovered;
    }

    public function setCostRecovered(string $costRecovered): self
    {
        $this->costRecovered = $costRecovered;
        return $this;
    }

    public function getProfitLine(): string
    {
        return $this->profitLine;
    }

    public function setProfitLine(string $profitLine): self
    {
        $this->profitLine = $profitLine;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}