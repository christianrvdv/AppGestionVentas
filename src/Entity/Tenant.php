<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenant')]
#[ORM\HasLifecycleCallbacks]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AppUser> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: AppUser::class)]
    private Collection $users;

    /** @var Collection<int, AppSetting> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: AppSetting::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $settings;

    /** @var Collection<int, UsdRate> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: UsdRate::class)]
    private Collection $usdRates;

    /** @var Collection<int, Product> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Product::class)]
    private Collection $products;

    /** @var Collection<int, Investment> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Investment::class)]
    private Collection $investments;

    /** @var Collection<int, Sale> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Sale::class)]
    private Collection $sales;

    /** @var Collection<int, Customer> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Customer::class)]
    private Collection $customers;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: Payment::class)]
    private Collection $payments;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->settings = new ArrayCollection();
        $this->usdRates = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->investments = new ArrayCollection();
        $this->sales = new ArrayCollection();
        $this->customers = new ArrayCollection();
        $this->payments = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
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

    /** @return Collection<int, AppUser> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(AppUser $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setTenant($this);
        }
        return $this;
    }

    public function removeUser(AppUser $user): self
    {
        $this->users->removeElement($user);
        return $this;
    }

    /** @return Collection<int, AppSetting> */
    public function getSettings(): Collection
    {
        return $this->settings;
    }

    public function addSetting(AppSetting $setting): self
    {
        if (!$this->settings->contains($setting)) {
            $this->settings->add($setting);
            $setting->setTenant($this);
        }
        return $this;
    }

    public function removeSetting(AppSetting $setting): self
    {
        $this->settings->removeElement($setting);
        return $this;
    }

    /** @return Collection<int, UsdRate> */
    public function getUsdRates(): Collection
    {
        return $this->usdRates;
    }

    public function addUsdRate(UsdRate $usdRate): self
    {
        if (!$this->usdRates->contains($usdRate)) {
            $this->usdRates->add($usdRate);
            $usdRate->setTenant($this);
        }
        return $this;
    }

    public function removeUsdRate(UsdRate $usdRate): self
    {
        $this->usdRates->removeElement($usdRate);
        return $this;
    }

    /** @return Collection<int, Product> */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): self
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setTenant($this);
        }
        return $this;
    }

    public function removeProduct(Product $product): self
    {
        $this->products->removeElement($product);
        return $this;
    }

    /** @return Collection<int, Investment> */
    public function getInvestments(): Collection
    {
        return $this->investments;
    }

    public function addInvestment(Investment $investment): self
    {
        if (!$this->investments->contains($investment)) {
            $this->investments->add($investment);
            $investment->setTenant($this);
        }
        return $this;
    }

    public function removeInvestment(Investment $investment): self
    {
        $this->investments->removeElement($investment);
        return $this;
    }

    /** @return Collection<int, Sale> */
    public function getSales(): Collection
    {
        return $this->sales;
    }

    public function addSale(Sale $sale): self
    {
        if (!$this->sales->contains($sale)) {
            $this->sales->add($sale);
            $sale->setTenant($this);
        }
        return $this;
    }

    public function removeSale(Sale $sale): self
    {
        $this->sales->removeElement($sale);
        return $this;
    }

    /** @return Collection<int, Customer> */
    public function getCustomers(): Collection
    {
        return $this->customers;
    }

    public function addCustomer(Customer $customer): self
    {
        if (!$this->customers->contains($customer)) {
            $this->customers->add($customer);
            $customer->setTenant($this);
        }
        return $this;
    }

    public function removeCustomer(Customer $customer): self
    {
        $this->customers->removeElement($customer);
        return $this;
    }

    /** @return Collection<int, Payment> */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setTenant($this);
        }
        return $this;
    }

    public function removePayment(Payment $payment): self
    {
        $this->payments->removeElement($payment);
        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
