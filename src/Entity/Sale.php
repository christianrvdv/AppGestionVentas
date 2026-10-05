<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SaleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SaleRepository::class)]
#[ORM\Table(name: 'sale')]
#[ORM\Index(name: 'idx_sale_tenant_date', columns: ['tenant_id', 'sale_date'])]
#[ORM\Index(name: 'idx_sale_tenant_investment', columns: ['tenant_id', 'investment_id'])]
#[ORM\HasLifecycleCallbacks]
class Sale
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'sales')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AppUser $createdBy;

    #[ORM\ManyToOne(targetEntity: Investment::class, inversedBy: 'sales')]
    #[ORM\JoinColumn(name: 'investment_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Investment $investment = null;

    #[ORM\Column(name: 'sale_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $saleDate;

    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalAmount = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, SaleLine>
     */
    #[ORM\OneToMany(mappedBy: 'sale', targetEntity: SaleLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->saleDate = new \DateTimeImmutable();
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

    public function getCreatedBy(): AppUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(AppUser $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getInvestment(): ?Investment
    {
        return $this->investment;
    }

    public function setInvestment(?Investment $investment): self
    {
        $this->investment = $investment;
        return $this;
    }

    public function getSaleDate(): \DateTimeImmutable
    {
        return $this->saleDate;
    }

    public function setSaleDate(\DateTimeImmutable $saleDate): self
    {
        $this->saleDate = $saleDate;
        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): self
    {
        $this->totalAmount = $totalAmount;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, SaleLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(SaleLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setSale($this);
        }
        return $this;
    }

    public function removeLine(SaleLine $line): self
    {
        $this->lines->removeElement($line);
        return $this;
    }
}