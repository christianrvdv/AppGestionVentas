<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentAllocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Aplicación de un pago a una venta (Fase 3f, ADR-0018).
 *
 * Es la tabla puente que hace M:N entre Payment y Sale: un anticipo puede
 * cubrir varias ventas y una venta puede ser cubierta por varios pagos.
 * El monto aplicado vive ACÁ, no en Payment: Payment.amount sigue siendo
 * "cuánto dinero entró" y allocation.amount es "cuánto de eso cubre esta
 * venta". La diferencia entre ambos es el remanente del pago.
 *
 * El tenant NO se duplica acá: se deriva del pago (Payment.tenant) y de la
 * venta, y PaymentService::applyToSale garantiza que coinciden antes de
 * crear la fila. Toda consulta filtra por tenant a través del join con
 * Payment, igual que el invariante de PaymentRepositoryInterface.
 *
 * unique (payment_id, sale_id): el mismo anticipo no se puede aplicar dos
 * veces a la misma venta. Para repartir un pago entre varias ventas no hace
 * falta ninguna otra restricción, y el índice único además sirve para las
 * búsquedas por payment_id.
 */
#[ORM\Entity(repositoryClass: PaymentAllocationRepository::class)]
#[ORM\Table(name: 'payment_allocation')]
#[ORM\UniqueConstraint(name: 'uniq_allocation_payment_sale', columns: ['payment_id', 'sale_id'])]
#[ORM\Index(name: 'idx_allocation_payment', columns: ['payment_id'])]
#[ORM\Index(name: 'idx_allocation_sale', columns: ['sale_id'])]
#[ORM\HasLifecycleCallbacks]
class PaymentAllocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Payment::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Payment $payment;

    /**
     * onDelete RESTRICT: una venta con importes aplicados no se borra (las
     * ventas se anulan, y SaleVoidService además lo prohibe explícitamente).
     */
    #[ORM\ManyToOne(targetEntity: Sale::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'sale_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Sale $sale;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function setPayment(Payment $payment): self
    {
        $this->payment = $payment;
        return $this;
    }

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function setSale(Sale $sale): self
    {
        $this->sale = $sale;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
