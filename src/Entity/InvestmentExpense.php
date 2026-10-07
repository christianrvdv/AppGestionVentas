<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvestmentExpenseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvestmentExpenseRepository::class)]
#[ORM\Table(name: 'investment_expense')]
#[ORM\Index(name: 'idx_expense_tenant_investment', columns: ['tenant_id', 'investment_id'])]
#[ORM\HasLifecycleCallbacks]
class InvestmentExpense
{
    public const CATEGORY_TRANSPORT = 'TRANSPORT';
    public const CATEGORY_FOOD = 'FOOD';
    public const CATEGORY_BATHROOM = 'BATHROOM';
    public const CATEGORY_SNACK = 'SNACK';
    public const CATEGORY_STORAGE = 'STORAGE';
    public const CATEGORY_OTHER = 'OTHER';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Investment::class, inversedBy: 'expenses')]
    #[ORM\JoinColumn(name: 'investment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Investment $investment;

    #[ORM\Column(type: Types::STRING, length: 30)]
    private string $category;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount;

    /**
     * Si true, este gasto se prorratea entre ítems vía ExpenseAllocation.
     * Si false, es gasto general de la inversión: afecta total_expenses
     * y por ende la inversión total, pero NO el costo real unitario de
     * ningún ítem. Sirve para modelar gastos no atribuibles.
     */
    #[ORM\Column(name: 'is_allocated', type: Types::BOOLEAN)]
    private bool $isAllocated = true;

    #[ORM\Column(name: 'usd_rate_snapshot', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $usdRateSnapshot = null;

    #[ORM\Column(name: 'amount_usd', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $amountUsd = null;

    #[ORM\Column(name: 'expense_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $expenseDate;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ExpenseAllocation> */
    #[ORM\OneToMany(mappedBy: 'investmentExpense', targetEntity: ExpenseAllocation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $allocations;

    public function __construct()
    {
        $this->allocations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->expenseDate = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertAllocationConsistency();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertAllocationConsistency();
    }

    /**
     * Invariante: un gasto NO prorrateable no puede tener allocations;
     * un gasto prorrateable debe tener al menos una (validación laxa,
     * la fuerte la hace el servicio al confirmar la inversión).
     */
    private function assertAllocationConsistency(): void
    {
        if (!$this->isAllocated && !$this->allocations->isEmpty()) {
            throw new \LogicException('Un gasto no prorrateable no puede tener allocations.');
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $c): self
    {
        $this->category = $c;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $d): self
    {
        $this->description = $d;
        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $a): self
    {
        $this->amount = $a;
        return $this;
    }

    public function isAllocated(): bool
    {
        return $this->isAllocated;
    }

    public function setIsAllocated(bool $v): self
    {
        $this->isAllocated = $v;
        return $this;
    }

    public function getUsdRateSnapshot(): ?string
    {
        return $this->usdRateSnapshot;
    }

    public function setUsdRateSnapshot(?string $v): self
    {
        $this->usdRateSnapshot = $v;
        return $this;
    }

    public function getAmountUsd(): ?string
    {
        return $this->amountUsd;
    }

    public function setAmountUsd(?string $v): self
    {
        $this->amountUsd = $v;
        return $this;
    }

    public function getExpenseDate(): \DateTimeImmutable
    {
        return $this->expenseDate;
    }

    public function setExpenseDate(\DateTimeImmutable $d): self
    {
        $this->expenseDate = $d;
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

    /** @return Collection<int, ExpenseAllocation> */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    public function addAllocation(ExpenseAllocation $a): self
    {
        if (!$this->allocations->contains($a)) {
            $this->allocations->add($a);
            $a->setInvestmentExpense($this);
        }
        return $this;
    }

    public function removeAllocation(ExpenseAllocation $a): self
    {
        $this->allocations->removeElement($a);
        return $this;
    }
}
