<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
#[ORM\Index(name: 'idx_payment_tenant_date', columns: ['tenant_id', 'payment_date'])]
#[ORM\Index(name: 'idx_payment_tenant_customer', columns: ['tenant_id', 'customer_id'])]
#[ORM\HasLifecycleCallbacks]
class Payment
{
    public const METHOD_CASH = 'CASH';
    public const METHOD_TRANSFER = 'TRANSFER';
    public const METHOD_CARD = 'CARD';
    public const METHOD_OTHER = 'OTHER';

    /**
     * Etiquetas en español de los métodos, para los selectores y las
     * tablas de la UI. Vive junto a las constantes que etiqueta para que
     * no se desincronicen (y para no repetir el mapa en cada controller).
     *
     * @var array<string, string>
     */
    public const METHOD_LABELS = [
        self::METHOD_CASH => 'Efectivo',
        self::METHOD_TRANSFER => 'Transferencia',
        self::METHOD_CARD => 'Tarjeta',
        self::METHOD_OTHER => 'Otro',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    /**
     * Cliente al que aplica el pago.
     *
     * [FIX-7] Estrategia B: nullable. El dominio contempla ventas/pagos de
     * mostrador sin cliente registrado (misma razón por la que
     * Sale.customer es nullable). Antes era nullable=false, lo que hacía
     * imposible persistir un pago sin cliente aunque el DTO lo permitía.
     */
    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Customer $customer = null;

    /**
     * Ventas que cubre este pago (Fase 3f, ADR-0018).
     *
     * Reemplaza al antiguo Payment.sale (un 1:N que no podía repartir un
     * anticipo entre varias ventas ni registrar varios pagos sobre una
     * misma venta). El monto aplicado vive en la allocation, no acá:
     * amount sigue siendo el dinero que entró.
     *
     * cascade persist: el alta de un pago contra una venta crea su
     * allocation en la misma operación y el flush del llamador las lleva
     * a las dos.
     *
     * @var Collection<int, PaymentAllocation>
     */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: PaymentAllocation::class, cascade: ['persist'])]
    private Collection $allocations;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount = '0.00';

    /**
     * Moneda del pago. Sin default: el servicio debe tomarla de
     * AppSetting::KEY_BASE_CURRENCY o de la moneda explícita del pago.
     */
    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency;

    #[ORM\Column(name: 'usd_rate_snapshot', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $usdRateSnapshot = null;

    #[ORM\Column(name: 'payment_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $paymentDate;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $method = self::METHOD_CASH;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->allocations = new ArrayCollection();
        $this->paymentDate = new \DateTimeImmutable();
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

    /** [FIX-7] Null = pago de mostrador / sin cliente registrado. */
    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): self
    {
        $this->customer = $customer;
        return $this;
    }

    /** @return Collection<int, PaymentAllocation> */
    public function getAllocations(): Collection
    {
        return $this->allocations;
    }

    public function addAllocation(PaymentAllocation $allocation): self
    {
        if (!$this->allocations->contains($allocation)) {
            $this->allocations->add($allocation);
            $allocation->setPayment($this);
        }
        return $this;
    }

    public function removeAllocation(PaymentAllocation $allocation): self
    {
        $this->allocations->removeElement($allocation);
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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
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

    public function getPaymentDate(): \DateTimeImmutable
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(\DateTimeImmutable $paymentDate): self
    {
        $this->paymentDate = $paymentDate;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
