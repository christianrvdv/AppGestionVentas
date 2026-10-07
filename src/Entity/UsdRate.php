<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UsdRateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UsdRateRepository::class)]
#[ORM\Table(name: 'usd_rate')]
#[ORM\Index(name: 'idx_usdrate_tenant_date', columns: ['tenant_id', 'rate_date'])]
#[ORM\Index(name: 'idx_usdrate_tenant_date_created', columns: ['tenant_id', 'rate_date', 'created_at'])]
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

    #[ORM\Column(name: 'is_correction', type: Types::BOOLEAN)]
    private bool $isCorrection = false;

    #[ORM\ManyToOne(targetEntity: UsdRate::class)]
    #[ORM\JoinColumn(name: 'supersedes_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?UsdRate $supersedes = null;

    #[ORM\ManyToOne(targetEntity: AppUser::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AppUser $createdBy = null;

    #[ORM\Column(name: 'source_note', type: Types::STRING, length: 100, nullable: true)]
    private ?string $sourceNote = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->rate = '0.0000';
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertRatePositive();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->assertRatePositive();
    }

    private function assertRatePositive(): void
    {
        if (bccomp($this->rate, '0', 4) <= 0) {
            throw new \LogicException('UsdRate.rate debe ser > 0.');
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

    public function getRateDate(): \DateTimeImmutable
    {
        return $this->rateDate;
    }

    public function setRateDate(\DateTimeImmutable $d): self
    {
        $this->rateDate = $d;
        return $this;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $r): self
    {
        $this->rate = $r;
        return $this;
    }

    public function isCorrection(): bool
    {
        return $this->isCorrection;
    }

    public function setIsCorrection(bool $v): self
    {
        $this->isCorrection = $v;
        return $this;
    }

    public function getSupersedes(): ?UsdRate
    {
        return $this->supersedes;
    }

    public function setSupersedes(?UsdRate $u): self
    {
        $this->supersedes = $u;
        return $this;
    }

    public function getCreatedBy(): ?AppUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?AppUser $u): self
    {
        $this->createdBy = $u;
        return $this;
    }

    public function getSourceNote(): ?string
    {
        return $this->sourceNote;
    }

    public function setSourceNote(?string $s): self
    {
        $this->sourceNote = $s;
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
