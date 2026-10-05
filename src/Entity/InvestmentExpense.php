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

    #[ORM\Column(name: 'expense_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $expenseDate;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, ExpenseAllocation>
     */
    #[ORM\OneToMany(mappedBy: 'investmentExpense', targetEntity: ExpenseAllocation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $allocations;

    public function __construct()
    {
        $this->allocations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->amount = '0.00';
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

    public function getInvestment(): Investment
    {
        return $this->investment;
    }

    public function setInvestment(Investment $investment): self
    {
        $this->investment = $investment;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getExpenseDate(): \DateTimeImmutable
    {
        return $this->expenseDate;
    }

    public function setExpenseDate(\DateTimeImmutable $expenseDate): self
    {
        $this->expenseDate = $expenseDate;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, ExpenseAllocation>
     */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    public function addAllocation(ExpenseAllocation $allocation): self
    {
        if (!$this->allocations->contains($allocation)) {
            $this->allocations->add($allocation);
            $allocation->setInvestmentExpense($this);
        }
        return $this;
    }

    public function removeAllocation(ExpenseAllocation $allocation): self
    {
        $this->allocations->removeElement($allocation);
        return $this;
    }
}