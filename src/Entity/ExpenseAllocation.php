<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExpenseAllocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExpenseAllocationRepository::class)]
#[ORM\Table(name: 'expense_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_allocation_expense_item', columns: ['investment_expense_id', 'investment_item_id'])]
class ExpenseAllocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: InvestmentExpense::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'investment_expense_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InvestmentExpense $investmentExpense;

    #[ORM\ManyToOne(targetEntity: InvestmentItem::class)]
    #[ORM\JoinColumn(name: 'investment_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InvestmentItem $investmentItem;

    #[ORM\Column(name: 'allocated_amount', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $allocatedAmount;

    #[ORM\Column(name: 'allocation_pct', type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $allocationPct;

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

    public function getInvestmentExpense(): InvestmentExpense
    {
        return $this->investmentExpense;
    }

    public function setInvestmentExpense(InvestmentExpense $investmentExpense): self
    {
        $this->investmentExpense = $investmentExpense;
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

    public function getAllocatedAmount(): string
    {
        return $this->allocatedAmount;
    }

    public function setAllocatedAmount(string $allocatedAmount): self
    {
        $this->allocatedAmount = $allocatedAmount;
        return $this;
    }

    public function getAllocationPct(): string
    {
        return $this->allocationPct;
    }

    public function setAllocationPct(string $allocationPct): self
    {
        $this->allocationPct = $allocationPct;
        return $this;
    }
}