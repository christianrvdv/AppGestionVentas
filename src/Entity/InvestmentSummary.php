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
#[ORM\Index(name: 'idx_summary_tenant_pending', columns: ['tenant_id', 'total_pending'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentSummary
{
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

    #[ORM\OneToOne(targetEntity: Investment::class)]
    #[ORM\JoinColumn(name: 'investment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Investment $investment;

    #[ORM\Column(name: 'total_investment', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalInvestment = '0.00';

    #[ORM\Column(name: 'total_investment_current', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalInvestmentCurrent = '0.00';

    #[ORM\Column(name: 'total_revaluation_gain_loss', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalRevaluationGainLoss = '0.00';

    #[ORM\Column(name: 'total_recovered', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalRecovered = '0.00';

    #[ORM\Column(name: 'total_recovered_per_product', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalRecoveredPerProduct = '0.00';

    #[ORM\Column(name: 'total_recovered_investment_first', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalRecoveredInvestmentFirst = '0.00';

    #[ORM\Column(name: 'total_recovered_current', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalRecoveredCurrent = '0.00';

    #[ORM\Column(name: 'total_gross_profit', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalGrossProfit = '0.00';

    #[ORM\Column(name: 'total_recognized_profit', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalRecognizedProfit = '0.00';

    #[ORM\Column(name: 'total_profit', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalProfit = '0.00';

    #[ORM\Column(name: 'total_profit_per_product', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalProfitPerProduct = '0.00';

    #[ORM\Column(name: 'total_profit_investment_first', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalProfitInvestmentFirst = '0.00';

    #[ORM\Column(name: 'total_pending', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalPending = '0.00';

    #[ORM\Column(name: 'total_pending_current', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalPendingCurrent = '0.00';

    #[ORM\Column(name: 'recovery_pct', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $recoveryPct = '0.00';

    #[ORM\Column(name: 'units_sold', type: Types::INTEGER)]
    private int $unitsSold = 0;

    #[ORM\Column(name: 'units_remaining', type: Types::INTEGER)]
    private int $unitsRemaining = 0;

    #[ORM\Column(name: 'units_lost', type: Types::INTEGER)]
    private int $unitsLost = 0;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function syncActiveMode(string $recoveryMode): void
    {
        if ($recoveryMode === Investment::RECOVERY_INVESTMENT_FIRST) {
            $this->totalRecovered = $this->totalRecoveredInvestmentFirst;
            $this->totalProfit = $this->totalProfitInvestmentFirst;
            return;
        }
        $this->totalRecovered = $this->totalRecoveredPerProduct;
        $this->totalProfit = $this->totalProfitPerProduct;
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

    public function getTotalInvestment(): string
    {
        return $this->totalInvestment;
    }

    public function setTotalInvestment(string $v): self
    {
        $this->totalInvestment = $v;
        return $this;
    }

    public function getTotalInvestmentCurrent(): string
    {
        return $this->totalInvestmentCurrent;
    }

    public function setTotalInvestmentCurrent(string $v): self
    {
        $this->totalInvestmentCurrent = $v;
        return $this;
    }

    public function getTotalRevaluationGainLoss(): string
    {
        return $this->totalRevaluationGainLoss;
    }

    public function setTotalRevaluationGainLoss(string $v): self
    {
        $this->totalRevaluationGainLoss = $v;
        return $this;
    }

    public function getTotalRecovered(): string
    {
        return $this->totalRecovered;
    }

    public function setTotalRecovered(string $v): self
    {
        $this->totalRecovered = $v;
        return $this;
    }

    public function getTotalRecoveredPerProduct(): string
    {
        return $this->totalRecoveredPerProduct;
    }

    public function setTotalRecoveredPerProduct(string $v): self
    {
        $this->totalRecoveredPerProduct = $v;
        return $this;
    }

    public function getTotalRecoveredInvestmentFirst(): string
    {
        return $this->totalRecoveredInvestmentFirst;
    }

    public function setTotalRecoveredInvestmentFirst(string $v): self
    {
        $this->totalRecoveredInvestmentFirst = $v;
        return $this;
    }

    public function getTotalRecoveredCurrent(): string
    {
        return $this->totalRecoveredCurrent;
    }

    public function setTotalRecoveredCurrent(string $v): self
    {
        $this->totalRecoveredCurrent = $v;
        return $this;
    }

    public function getTotalGrossProfit(): string
    {
        return $this->totalGrossProfit;
    }

    public function setTotalGrossProfit(string $v): self
    {
        $this->totalGrossProfit = $v;
        return $this;
    }

    public function getTotalRecognizedProfit(): string
    {
        return $this->totalRecognizedProfit;
    }

    public function setTotalRecognizedProfit(string $v): self
    {
        $this->totalRecognizedProfit = $v;
        return $this;
    }

    public function getTotalProfit(): string
    {
        return $this->totalProfit;
    }

    public function setTotalProfit(string $v): self
    {
        $this->totalProfit = $v;
        return $this;
    }

    public function getTotalProfitPerProduct(): string
    {
        return $this->totalProfitPerProduct;
    }

    public function setTotalProfitPerProduct(string $v): self
    {
        $this->totalProfitPerProduct = $v;
        return $this;
    }

    public function getTotalProfitInvestmentFirst(): string
    {
        return $this->totalProfitInvestmentFirst;
    }

    public function setTotalProfitInvestmentFirst(string $v): self
    {
        $this->totalProfitInvestmentFirst = $v;
        return $this;
    }

    public function getTotalPending(): string
    {
        return $this->totalPending;
    }

    public function setTotalPending(string $v): self
    {
        $this->totalPending = $v;
        return $this;
    }

    public function getTotalPendingCurrent(): string
    {
        return $this->totalPendingCurrent;
    }

    public function setTotalPendingCurrent(string $v): self
    {
        $this->totalPendingCurrent = $v;
        return $this;
    }

    public function getRecoveryPct(): string
    {
        return $this->recoveryPct;
    }

    public function setRecoveryPct(string $v): self
    {
        $this->recoveryPct = $v;
        return $this;
    }

    public function getUnitsSold(): int
    {
        return $this->unitsSold;
    }

    public function setUnitsSold(int $v): self
    {
        $this->unitsSold = $v;
        return $this;
    }

    public function getUnitsRemaining(): int
    {
        return $this->unitsRemaining;
    }

    public function setUnitsRemaining(int $v): self
    {
        $this->unitsRemaining = $v;
        return $this;
    }

    public function getUnitsLost(): int
    {
        return $this->unitsLost;
    }

    public function setUnitsLost(int $v): self
    {
        $this->unitsLost = $v;
        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $v): self
    {
        $this->confirmedAt = $v;
        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $v): self
    {
        $this->closedAt = $v;
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
}
