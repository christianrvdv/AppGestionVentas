<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'product')]
#[ORM\UniqueConstraint(name: 'uniq_product_tenant_sku', columns: ['tenant_id', 'sku'])]
#[ORM\Index(name: 'idx_product_tenant', columns: ['tenant_id'])]
#[ORM\HasLifecycleCallbacks]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'products')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'default_margin_pct', type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $defaultMarginPct = null;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, InvestmentItem>
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: InvestmentItem::class)]
    private Collection $investmentItems;

    public function __construct()
    {
        $this->investmentItems = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): self
    {
        $this->sku = $sku;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getDefaultMarginPct(): ?string
    {
        return $this->defaultMarginPct;
    }

    public function setDefaultMarginPct(?string $defaultMarginPct): self
    {
        $this->defaultMarginPct = $defaultMarginPct;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
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
    public function getInvestmentItems(): Collection
    {
        return $this->investmentItems;
    }

    public function addInvestmentItem(InvestmentItem $investmentItem): self
    {
        if (!$this->investmentItems->contains($investmentItem)) {
            $this->investmentItems->add($investmentItem);
            $investmentItem->setProduct($this);
        }
        return $this;
    }

    public function removeInvestmentItem(InvestmentItem $investmentItem): self
    {
        $this->investmentItems->removeElement($investmentItem);
        return $this;
    }
}