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
#[ORM\Index(name: 'idx_sale_tenant_customer', columns: ['tenant_id', 'customer_id'])]
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
    #[ORM\JoinColumn(name: 'investment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Investment $investment;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'sales')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    /**
     * Moneda de la venta. Debe coincidir con Investment.baseCurrency.
     * Se persiste explícitamente para que Payment pueda validar
     * coincidencia y para que reportes no dependan de un join.
     */
    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency;

    #[ORM\Column(name: 'sale_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $saleDate;

    #[ORM\Column(name: 'total_amount', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalAmount = '0.00';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'voided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'void_reason', type: Types::STRING, length: 255, nullable: true)]
    private ?string $voidReason = null;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'voided_by', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?AppUser $voidedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SaleLine> */
    #[ORM\OneToMany(mappedBy: 'sale', targetEntity: SaleLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(mappedBy: 'sale', targetEntity: Payment::class)]
    private Collection $payments;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->saleDate = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertCurrencyMatchesInvestment();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertCurrencyMatchesInvestment();
    }

    private function assertCurrencyMatchesInvestment(): void
    {
        if (isset($this->investment) && $this->currency !== $this->investment->getBaseCurrency()) {
            throw new \LogicException(sprintf(
                'Sale.currency (%s) debe coincidir con Investment.baseCurrency (%s).',
                $this->currency,
                $this->investment->getBaseCurrency()
            ));
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

    public function getCreatedBy(): AppUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(AppUser $u): self
    {
        $this->createdBy = $u;
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

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $c): self
    {
        $this->customer = $c;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $c): self
    {
        $this->currency = $c;
        return $this;
    }

    public function getSaleDate(): \DateTimeImmutable
    {
        return $this->saleDate;
    }

    public function setSaleDate(\DateTimeImmutable $d): self
    {
        $this->saleDate = $d;
        return $this;
    }

    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $v): self
    {
        $this->totalAmount = $v;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $n): self
    {
        $this->notes = $n;
        return $this;
    }

    public function getVoidedAt(): ?\DateTimeImmutable
    {
        return $this->voidedAt;
    }

    public function setVoidedAt(?\DateTimeImmutable $v): self
    {
        $this->voidedAt = $v;
        return $this;
    }

    public function getVoidReason(): ?string
    {
        return $this->voidReason;
    }

    public function setVoidReason(?string $v): self
    {
        $this->voidReason = $v;
        return $this;
    }

    public function getVoidedBy(): ?AppUser
    {
        return $this->voidedBy;
    }

    public function setVoidedBy(?AppUser $u): self
    {
        $this->voidedBy = $u;
        return $this;
    }

    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, SaleLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(SaleLine $l): self
    {
        if (!$this->lines->contains($l)) {
            $this->lines->add($l);
            $l->setSale($this);
        }
        return $this;
    }

    public function removeLine(SaleLine $l): self
    {
        $this->lines->removeElement($l);
        return $this;
    }

    /** @return Collection<int, Payment> */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $p): self
    {
        if (!$this->payments->contains($p)) {
            $this->payments->add($p);
            $p->setSale($this);
        }
        return $this;
    }

    public function removePayment(Payment $p): self
    {
        $this->payments->removeElement($p);
        return $this;
    }
}
