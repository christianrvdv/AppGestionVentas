<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UsdRateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UsdRateRepository::class)]
#[ORM\Table(name: 'usd_rate')]
#[ORM\UniqueConstraint(name: 'uniq_usdrate_tenant_date', columns: ['tenant_id', 'rate_date'])]
#[ORM\HasLifecycleCallbacks]
class UsdRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'usdRates')]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\Column(name: 'rate_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $rateDate;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4)]
    private string $rate;

    #[ORM\Column(name: 'source_note', type: Types::STRING, length: 100, nullable: true)]
    private ?string $sourceNote = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->rateDate = new \DateTimeImmutable();
        $this->rate = '0.0000';
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

    public function getRateDate(): \DateTimeImmutable
    {
        return $this->rateDate;
    }

    public function setRateDate(\DateTimeImmutable $rateDate): self
    {
        $this->rateDate = $rateDate;
        return $this;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;
        return $this;
    }

    public function getSourceNote(): ?string
    {
        return $this->sourceNote;
    }

    public function setSourceNote(?string $sourceNote): self
    {
        $this->sourceNote = $sourceNote;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}