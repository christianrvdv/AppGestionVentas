<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvestmentSummaryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvestmentSummaryRepository::class)]
#[ORM\Table(name: 'investment_summary')]
#[ORM\UniqueConstraint(name: 'uniq_summary_investment', columns: ['investment_id'])]
#[ORM\Index(name: 'idx_summary_tenant', columns: ['tenant_id'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentSummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\OneToOne(targetEntity: Investment::class)]
    #[ORM\JoinColumn(name: 'investment_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'RESTRICT')]
    // onDelete: RESTRICT prevents cascade deletion. The summary must be deleted explicitly
    // before its investment to preserve audit trail and avoid orphaned summary records.
    private Investment $investment;

    #[ORM\Column(name: 'total_investment', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalInvestment = '0.00';

    #[ORM\Column(name: 'total_recovered', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalRecovered = '0.00';

    #[ORM\Column(name: 'total_profit', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalProfit = '0.00';

    #[ORM\Column(name: 'total_pending', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalPending = '0.00';

    #[ORM\Column(name: 'recovery_pct', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $recoveryPct = '0.00';

    #[ORM\Column(name: 'units_sold', type: Types::INTEGER)]
    private int $unitsSold = 0;

    #[ORM\Column(name: 'units_remaining', type: Types::INTEGER)]
    private int $unitsRemaining = 0;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
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

    public function getTotalInvestment(): string
    {
        return $this->totalInvestment;
    }

    public function setTotalInvestment(string $totalInvestment): self
    {
        $this->totalInvestment = $totalInvestment;
        return $this;
    }

    public function getTotalRecovered(): string
    {
        return $this->totalRecovered;
    }

    public function setTotalRecovered(string $totalRecovered): self
    {
        $this->totalRecovered = $totalRecovered;
        return $this;
    }

    public function getTotalProfit(): string
    {
        return $this->totalProfit;
    }

    public function setTotalProfit(string $totalProfit): self
    {
        $this->totalProfit = $totalProfit;
        return $this;
    }

    public function getTotalPending(): string
    {
        return $this->totalPending;
    }

    public function setTotalPending(string $totalPending): self
    {
        $this->totalPending = $totalPending;
        return $this;
    }

    public function getRecoveryPct(): string
    {
        return $this->recoveryPct;
    }

    public function setRecoveryPct(string $recoveryPct): self
    {
        $this->recoveryPct = $recoveryPct;
        return $this;
    }

    public function getUnitsSold(): int
    {
        return $this->unitsSold;
    }

    public function setUnitsSold(int $unitsSold): self
    {
        $this->unitsSold = $unitsSold;
        return $this;
    }

    public function getUnitsRemaining(): int
    {
        return $this->unitsRemaining;
    }

    public function setUnitsRemaining(int $unitsRemaining): self
    {
        $this->unitsRemaining = $unitsRemaining;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * This table is updated by InvestmentSummaryUpdater service, not by database triggers.
     * The service recomputes all fields from source tables (investment, investment_item,
     * sale_line, inventory_movement) and persists the snapshot. This ensures consistency
     * and allows the summary to be refreshed on demand.
     */
}