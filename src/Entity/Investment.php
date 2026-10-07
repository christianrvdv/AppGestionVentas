<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvestmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvestmentRepository::class)]
#[ORM\Table(name: 'investment')]
#[ORM\UniqueConstraint(name: 'uniq_investment_tenant_code', columns: ['tenant_id', 'code'])]
#[ORM\Index(name: 'idx_investment_tenant_date', columns: ['tenant_id', 'investment_date'])]
#[ORM\Index(name: 'idx_investment_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_investment_tenant_confirmed', columns: ['tenant_id', 'confirmed_at'])]
#[ORM\HasLifecycleCallbacks]
class Investment
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_RECOVERED = 'RECOVERED';
    public const STATUS_CLOSED = 'CLOSED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const RECOVERY_PER_PRODUCT = 'PER_PRODUCT';
    public const RECOVERY_INVESTMENT_FIRST = 'INVESTMENT_FIRST';

    public const ALLOCATION_METHOD_VALUE = 'VALUE';
    public const ALLOCATION_METHOD_QUANTITY = 'QUANTITY';
    public const ALLOCATION_METHOD_EQUAL = 'EQUAL';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'investments')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AppUser $createdBy;

    /**
     * Código visible por el usuario.
     * Formato: {PREFIJO_TENANT}-{YYYYMM}-{SECUENCIA_4}.
     * Ejemplo: "MZ-202601-0007". Único por tenant.
     * La generación corresponde a InvestmentCodeGeneratorService.
     */
    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $code;

    #[ORM\Column(name: 'investment_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $investmentDate;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'cancelled_reason', type: Types::STRING, length: 255, nullable: true)]
    private ?string $cancelledReason = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'base_currency', type: Types::STRING, length: 3)]
    private string $baseCurrency;

    #[ORM\Column(name: 'usd_rate_snapshot', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $usdRateSnapshot = null;

    #[ORM\Column(name: 'current_usd_rate', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $currentUsdRate = null;

    /**
     * Snapshot estructural. Fuente autoritativa del costo de la inversión.
     * investment_summary.total_investment es una copia denormalizada para
     * reportes y NO debe escribirse fuera de InvestmentSummaryService.
     */
    #[ORM\Column(name: 'total_merchandise_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalMerchandiseCost = '0.00';

    #[ORM\Column(name: 'total_expenses', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalExpenses = '0.00';

    /**
     * Snapshot histórico a la tasa de la inversión.
     * NO se revalúa. La versión revaluada vive en investment_summary.total_investment_current.
     */
    #[ORM\Column(name: 'total_investment', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalInvestment = '0.00';

    #[ORM\Column(name: 'allocation_method', type: Types::STRING, length: 20)]
    private string $allocationMethod = self::ALLOCATION_METHOD_VALUE;

    #[ORM\Column(name: 'recovery_mode', type: Types::STRING, length: 20)]
    private string $recoveryMode = self::RECOVERY_PER_PRODUCT;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'last_revalued_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRevaluedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, InvestmentItem> */
    #[ORM\OneToMany(mappedBy: 'investment', targetEntity: InvestmentItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /** @var Collection<int, InvestmentExpense> */
    #[ORM\OneToMany(mappedBy: 'investment', targetEntity: InvestmentExpense::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $expenses;

    /** @var Collection<int, Sale> */
    #[ORM\OneToMany(mappedBy: 'investment', targetEntity: Sale::class)]
    private Collection $sales;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->expenses = new ArrayCollection();
        $this->sales = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertStatusConsistency();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertStatusConsistency();
    }

    /**
     * Invariante: CANCELLED requiere cancelledAt; CLOSED requiere closedAt.
     * Las demás combinaciones son válidas.
     */
    private function assertStatusConsistency(): void
    {
        if ($this->status === self::STATUS_CANCELLED && $this->cancelledAt === null) {
            throw new \LogicException('Investment en estado CANCELLED requiere cancelledAt.');
        }
        if ($this->status === self::STATUS_CLOSED && $this->closedAt === null) {
            throw new \LogicException('Investment en estado CLOSED requiere closedAt.');
        }
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

    public function getCreatedBy(): AppUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(AppUser $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getInvestmentDate(): \DateTimeImmutable
    {
        return $this->investmentDate;
    }

    public function setInvestmentDate(\DateTimeImmutable $investmentDate): self
    {
        $this->investmentDate = $investmentDate;
        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): self
    {
        $this->confirmedAt = $confirmedAt;
        return $this;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmedAt !== null;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function isClosed(): bool
    {
        return $this->closedAt !== null;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getCancelledReason(): ?string
    {
        return $this->cancelledReason;
    }

    public function setCancelledReason(?string $cancelledReason): self
    {
        $this->cancelledReason = $cancelledReason;
        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function setBaseCurrency(string $baseCurrency): self
    {
        $this->baseCurrency = $baseCurrency;
        return $this;
    }

    public function getUsdRateSnapshot(): ?string
    {
        return $this->usdRateSnapshot;
    }

    public function setUsdRateSnapshot(?string $usdRateSnapshot): self
    {
        $this->usdRateSnapshot = $usdRateSnapshot;
        return $this;
    }

    public function getCurrentUsdRate(): ?string
    {
        return $this->currentUsdRate;
    }

    public function setCurrentUsdRate(?string $currentUsdRate): self
    {
        $this->currentUsdRate = $currentUsdRate;
        return $this;
    }

    public function getTotalMerchandiseCost(): string
    {
        return $this->totalMerchandiseCost;
    }

    public function setTotalMerchandiseCost(string $v): self
    {
        $this->totalMerchandiseCost = $v;
        return $this;
    }

    public function getTotalExpenses(): string
    {
        return $this->totalExpenses;
    }

    public function setTotalExpenses(string $v): self
    {
        $this->totalExpenses = $v;
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

    public function getAllocationMethod(): string
    {
        return $this->allocationMethod;
    }

    public function setAllocationMethod(string $m): self
    {
        $this->allocationMethod = $m;
        return $this;
    }

    public function getRecoveryMode(): string
    {
        return $this->recoveryMode;
    }

    public function setRecoveryMode(string $m): self
    {
        $this->recoveryMode = $m;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $s): self
    {
        $this->status = $s;
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

    /** @return Collection<int, InvestmentItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(InvestmentItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setInvestment($this);
        }
        return $this;
    }

    public function removeItem(InvestmentItem $item): self
    {
        $this->items->removeElement($item);
        return $this;
    }

    /** @return Collection<int, InvestmentExpense> */
    public function getExpenses(): Collection
    {
        return $this->expenses;
    }

    public function addExpense(InvestmentExpense $e): self
    {
        if (!$this->expenses->contains($e)) {
            $this->expenses->add($e);
            $e->setInvestment($this);
        }
        return $this;
    }

    public function removeExpense(InvestmentExpense $e): self
    {
        $this->expenses->removeElement($e);
        return $this;
    }

    /** @return Collection<int, Sale> */
    public function getSales(): Collection
    {
        return $this->sales;
    }

    public function addSale(Sale $s): self
    {
        if (!$this->sales->contains($s)) {
            $this->sales->add($s);
            $s->setInvestment($this);
        }
        return $this;
    }

    public function removeSale(Sale $s): self
    {
        $this->sales->removeElement($s);
        return $this;
    }
}
