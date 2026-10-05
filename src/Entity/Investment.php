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
#[ORM\HasLifecycleCallbacks]
class Investment
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_RECOVERED = 'RECOVERED';
    public const STATUS_CLOSED = 'CLOSED';

    public const RECOVERY_PER_PRODUCT = 'PER_PRODUCT';
    public const RECOVERY_INVESTMENT_FIRST = 'INVESTMENT_FIRST';

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

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $code;

    #[ORM\Column(name: 'investment_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $investmentDate;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'base_currency', type: Types::STRING, length: 3)]
    private string $baseCurrency = 'CUP';

    #[ORM\Column(name: 'usd_rate_snapshot', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $usdRateSnapshot = null;

    #[ORM\Column(name: 'total_merchandise_cost', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalMerchandiseCost = '0.00';

    #[ORM\Column(name: 'total_expenses', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalExpenses = '0.00';

    #[ORM\Column(name: 'total_investment', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalInvestment = '0.00';

    #[ORM\Column(name: 'recovery_mode', type: Types::STRING, length: 20)]
    private string $recoveryMode;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, InvestmentItem>
     */
    #[ORM\OneToMany(mappedBy: 'investment', targetEntity: InvestmentItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    /**
     * @var Collection<int, InvestmentExpense>
     */
    #[ORM\OneToMany(mappedBy: 'investment', targetEntity: InvestmentExpense::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $expenses;

    /**
     * @var Collection<int, Sale>
     */
    #[ORM\OneToMany(mappedBy: 'investment', targetEntity: Sale::class)]
    private Collection $sales;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->expenses = new ArrayCollection();
        $this->sales = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_OPEN;
        $this->recoveryMode = self::RECOVERY_PER_PRODUCT;
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

    public function getTotalMerchandiseCost(): string
    {
        return $this->totalMerchandiseCost;
    }

    public function setTotalMerchandiseCost(string $totalMerchandiseCost): self
    {
        $this->totalMerchandiseCost = $totalMerchandiseCost;
        return $this;
    }

    public function getTotalExpenses(): string
    {
        return $this->totalExpenses;
    }

    public function setTotalExpenses(string $totalExpenses): self
    {
        $this->totalExpenses = $totalExpenses;
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

    public function getRecoveryMode(): string
    {
        return $this->recoveryMode;
    }

    public function setRecoveryMode(string $recoveryMode): self
    {
        $this->recoveryMode = $recoveryMode;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
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
     * @return Collection<int, InvestmentItem>
     */
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

    /**
     * @return Collection<int, InvestmentExpense>
     */
    public function getExpenses(): Collection
    {
        return $this->expenses;
    }

    public function addExpense(InvestmentExpense $expense): self
    {
        if (!$this->expenses->contains($expense)) {
            $this->expenses->add($expense);
            $expense->setInvestment($this);
        }
        return $this;
    }

    public function removeExpense(InvestmentExpense $expense): self
    {
        $this->expenses->removeElement($expense);
        return $this;
    }

    /**
     * @return Collection<int, Sale>
     */
    public function getSales(): Collection
    {
        return $this->sales;
    }

    public function addSale(Sale $sale): self
    {
        if (!$this->sales->contains($sale)) {
            $this->sales->add($sale);
            $sale->setInvestment($this);
        }
        return $this;
    }

    public function removeSale(Sale $sale): self
    {
        $this->sales->removeElement($sale);
        return $this;
    }
}