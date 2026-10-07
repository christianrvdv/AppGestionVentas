<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ItemCostRevaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ItemCostRevaluationRepository::class)]
#[ORM\Table(name: 'item_cost_revaluation')]
#[ORM\Index(name: 'idx_revaluation_tenant_item', columns: ['tenant_id', 'investment_item_id'])]
#[ORM\Index(name: 'idx_revaluation_tenant_date', columns: ['tenant_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class ItemCostRevaluation
{
    public const SOURCE_MANUAL = 'MANUAL';
    public const SOURCE_SCHEDULED = 'SCHEDULED';
    public const SOURCE_SALE = 'SALE';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: InvestmentItem::class, inversedBy: 'revaluations')]
    #[ORM\JoinColumn(name: 'investment_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InvestmentItem $investmentItem;

    #[ORM\ManyToOne(targetEntity: UsdRate::class)]
    #[ORM\JoinColumn(name: 'usd_rate_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?UsdRate $usdRate = null;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'triggered_by', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AppUser $triggeredBy = null;

    #[ORM\Column(name: 'source', type: Types::STRING, length: 20)]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'old_rate', type: Types::DECIMAL, precision: 12, scale: 4)]
    private string $oldRate;

    #[ORM\Column(name: 'new_rate', type: Types::DECIMAL, precision: 12, scale: 4)]
    private string $newRate;

    #[ORM\Column(name: 'old_real_unit_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $oldRealUnitCost;

    #[ORM\Column(name: 'new_real_unit_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $newRealUnitCost;

    #[ORM\Column(name: 'old_suggested_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $oldSuggestedPrice;

    #[ORM\Column(name: 'new_suggested_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $newSuggestedPrice;

    /**
     * Unidades pendientes al momento de la revaluación.
     * Sin este campo, la ganancia/pérdida por revaluación de un período
     * no es reconstruible.
     */
    #[ORM\Column(name: 'quantity_snapshot', type: Types::INTEGER)]
    private int $quantitySnapshot = 0;

    /**
     * RevaluationGainLoss = quantitySnapshot * (newRealUnitCost - oldRealUnitCost).
     * Positivo = el costo subió (pérdida latente).
     * Negativo = el costo bajó (ganancia latente).
     * Se persiste para evitar recomputar y para permitir agregaciones rápidas.
     */
    #[ORM\Column(name: 'revaluation_gain_loss', type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $revaluationGainLoss = '0.00';

    #[ORM\Column(name: 'reason', type: Types::STRING, length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->oldRate = '0.0000';
        $this->newRate = '0.0000';
        $this->oldRealUnitCost = '0.00';
        $this->newRealUnitCost = '0.00';
        $this->oldSuggestedPrice = '0.00';
        $this->newSuggestedPrice = '0.00';
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->recalculateGainLoss();
    }

    private function recalculateGainLoss(): void
    {
        $diff = bcsub($this->newRealUnitCost, $this->oldRealUnitCost, 4);
        $this->revaluationGainLoss = bcmul($diff, (string)$this->quantitySnapshot, 2);
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

    public function getUsdRate(): ?UsdRate
    {
        return $this->usdRate;
    }

    public function setUsdRate(?UsdRate $r): self
    {
        $this->usdRate = $r;
        return $this;
    }

    public function getTriggeredBy(): ?AppUser
    {
        return $this->triggeredBy;
    }

    public function setTriggeredBy(?AppUser $u): self
    {
        $this->triggeredBy = $u;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $s): self
    {
        $this->source = $s;
        return $this;
    }

    public function getOldRate(): string
    {
        return $this->oldRate;
    }

    public function setOldRate(string $v): self
    {
        $this->oldRate = $v;
        return $this;
    }

    public function getNewRate(): string
    {
        return $this->newRate;
    }

    public function setNewRate(string $v): self
    {
        $this->newRate = $v;
        return $this;
    }

    public function getOldRealUnitCost(): string
    {
        return $this->oldRealUnitCost;
    }

    public function setOldRealUnitCost(string $v): self
    {
        $this->oldRealUnitCost = $v;
        return $this;
    }

    public function getNewRealUnitCost(): string
    {
        return $this->newRealUnitCost;
    }

    public function setNewRealUnitCost(string $v): self
    {
        $this->newRealUnitCost = $v;
        return $this;
    }

    public function getOldSuggestedPrice(): string
    {
        return $this->oldSuggestedPrice;
    }

    public function setOldSuggestedPrice(string $v): self
    {
        $this->oldSuggestedPrice = $v;
        return $this;
    }

    public function getNewSuggestedPrice(): string
    {
        return $this->newSuggestedPrice;
    }

    public function setNewSuggestedPrice(string $v): self
    {
        $this->newSuggestedPrice = $v;
        return $this;
    }

    public function getQuantitySnapshot(): int
    {
        return $this->quantitySnapshot;
    }

    public function setQuantitySnapshot(int $v): self
    {
        $this->quantitySnapshot = $v;
        return $this;
    }

    public function getRevaluationGainLoss(): string
    {
        return $this->revaluationGainLoss;
    }

    public function setRevaluationGainLoss(string $v): self
    {
        $this->revaluationGainLoss = $v;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $r): self
    {
        $this->reason = $r;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
