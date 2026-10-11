<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SaleLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SaleLineRepository::class)]
#[ORM\Table(name: 'sale_line')]
#[ORM\Index(name: 'idx_saleline_tenant_sale', columns: ['tenant_id', 'sale_id'])]
#[ORM\Index(name: 'idx_saleline_tenant_item', columns: ['tenant_id', 'investment_item_id'])]
#[ORM\Index(name: 'idx_saleline_tenant_created', columns: ['tenant_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class SaleLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Sale::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'sale_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Sale $sale;

    #[ORM\ManyToOne(targetEntity: InvestmentItem::class, inversedBy: 'saleLines')]
    #[ORM\JoinColumn(name: 'investment_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private InvestmentItem $investmentItem;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $unitPrice;

    #[ORM\Column(name: 'total_line', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $totalLine = '0.00';

    #[ORM\Column(name: 'usd_rate_snapshot', type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $usdRateSnapshot = null;

    #[ORM\Column(name: 'real_unit_cost_snapshot', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $realUnitCostSnapshot;

    #[ORM\Column(name: 'current_cost_snapshot', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $currentCostSnapshot = '0.00';

    #[ORM\Column(name: 'cost_recovered', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $costRecovered = '0.00';

    #[ORM\Column(name: 'gross_profit_line', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $grossProfitLine = '0.00';

    /**
     * [PHASE-0] Ganancia reconocida de la línea.
     *
     * Depende del recovery_mode de la inversión:
     *   - PER_PRODUCT: coincide con grossProfitLine.
     *   - INVESTMENT_FIRST: porción de grossProfitLine que excede la
     *     inversión aún no recuperada al momento de la venta.
     *
     * Se asigna EXCLUSIVAMENTE vía applyRecognizedProfit().
     * No hay setter público: un setter genérico permitía sobrescribir
     * el valor sin validar la invariante contable.
     */
    #[ORM\Column(name: 'recognized_profit_line', type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $recognizedProfitLine = '0.00';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->recalculateDerived();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->recalculateDerived();
    }

    /**
     * [FIX-1] Recalcula totalLine EN EL MOMENTO de asignar quantity/unitPrice.
     *
     * Motivo: Doctrine dispara los callbacks PrePersist en orden de dependencia
     * de claves foráneas, es decir PRIMERO Sale y DESPUÉS SaleLine. Si
     * totalLine solo se calculara en SaleLine::onPrePersist, cuando
     * Sale::onPrePersist suma las líneas todas leerían '0.00' y
     * Sale.totalAmount se persistiría en cero.
     *
     * Al calcularlo en los setters, la línea llega a Sale con totalLine ya
     * correcto, con o sin flush intermedio.
     *
     * Es defensivo con isset(): los setters pueden invocarse en cualquier
     * orden y aún no ambos estar seteados.
     */
    private function recalculateTotalLine(): void
    {
        if (isset($this->quantity, $this->unitPrice)) {
            $this->totalLine = bcmul($this->unitPrice, (string)$this->quantity, 2);
        }
    }

    /**
     * Recalcula los derivados deterministas de la línea.
     * recognizedProfitLine NO se recalcula aquí: es responsabilidad
     * explícita de SaleService vía applyRecognizedProfit().
     *
     * NOTA [FIX-1]: totalLine también se calcula aquí (defensa en
     * profundidad), pero la fuente de verdad en memoria es el setter,
     * porque este método solo corre en PrePersist/PreUpdate, demasiado
     * tarde para que Sale::onPrePersist lo vea.
     */
    private function recalculateDerived(): void
    {
        // Los setters pueden invocarse en cualquier orden: si todavía falta
        // alguno de los tres valores que alimentan los derivados, no hay nada
        // que calcular (igual que hace recalculateTotalLine con isset).
        if (!isset($this->quantity, $this->unitPrice, $this->realUnitCostSnapshot)) {
            return;
        }

        if ($this->quantity <= 0) {
            throw new \LogicException(sprintf(
                'SaleLine.quantity debe ser > 0 (id=%s).',
                $this->id ?? 'new'
            ));
        }

        $qty = (string)$this->quantity;

        $this->totalLine = bcmul($this->unitPrice, $qty, 2);
        $this->costRecovered = bcmul($this->realUnitCostSnapshot, $qty, 2);
        $this->grossProfitLine = bcsub($this->totalLine, $this->costRecovered, 2);
    }

    /**
     * [PHASE-0] Asigna la ganancia reconocida de esta línea.
     *
     * Método explícito y validado. Solo RecoveryRecognitionService (S6)
     * debe invocarlo, después de calcular el modo de recuperación de la
     * inversión.
     *
     * [FIX] Antes de validar, recalculamos los derivados. Razón:
     * `grossProfitLine` solo se computa en PrePersist/PreUpdate, es
     * decir, DESPUÉS de que el servicio termine de armar la entidad.
     * Si el servicio llama a este método tras setear unitPrice,
     * quantity y realUnitCostSnapshot (el flujo natural), la
     * validación del techo compararía contra '0.00' y rompería toda
     * venta con ganancia positiva.
     *
     * Al recalcular aquí, garantizamos que la validación use el
     * grossProfitLine real. El recálculo es idempotente y barato.
     *
     * Invariantes (ADR-0002, sección "Manejo de ventas bajo costo"):
     *   - recognizedProfitLine >= 0 (nunca se reconoce pérdida).
     *   - recognizedProfitLine <= max(0, grossProfitLine). El techo es
     *     el grossProfitLine solo cuando es positivo; en una línea bajo
     *     costo (grossProfitLine < 0) el techo es '0.00', de modo que la
     *     única asignación válida es cero.
     *   - La pérdida vive en grossProfitLine (negativo): es lo que
     *     alimenta InvestmentSummary.totalGrossProfit, no el
     *     recognizedProfitLine.
     */
    public function applyRecognizedProfit(string $amount): self
    {
        $this->recalculateDerived();

        if (bccomp($amount, '0', 2) < 0) {
            throw new \LogicException(sprintf(
                'recognizedProfitLine no puede ser negativo (recibido: %s).',
                $amount
            ));
        }

        // [ADR-0002] Cota superior = max(0, grossProfitLine): una línea
        // bajo costo no reconoce nada, no admite 'amount <= grossProfitLine'
        // porque ese comparando sería negativo y rechazaría incluso '0.00'.
        $cap = bccomp($this->grossProfitLine, '0', 2) > 0
            ? $this->grossProfitLine
            : '0.00';

        if (bccomp($amount, $cap, 2) > 0) {
            throw new \LogicException(sprintf(
                'recognizedProfitLine (%s) no puede superar el máximo reconocible (%s): grossProfitLine = %s.',
                $amount,
                $cap,
                $this->grossProfitLine
            ));
        }

        $this->recognizedProfitLine = $amount;
        return $this;
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

    public function getSale(): Sale
    {
        return $this->sale;
    }

    public function setSale(Sale $s): self
    {
        $this->sale = $s;
        return $this;
    }

    public function getInvestmentItem(): InvestmentItem
    {
        return $this->investmentItem;
    }

    public function setInvestmentItem(InvestmentItem $i): self
    {
        $this->investmentItem = $i;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $q): self
    {
        $this->quantity = $q;
        // [FIX-1] Eager: mantiene totalLine sincronizada siempre.
        $this->recalculateTotalLine();
        $this->notifySaleTotal();
        return $this;
    }

    public function getUnitPrice(): string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $p): self
    {
        $this->unitPrice = $p;
        // [FIX-1] Eager: mantiene totalLine sincronizada siempre.
        $this->recalculateTotalLine();
        $this->notifySaleTotal();
        return $this;
    }

    /**
     * [FIX-1] Propaga el recálculo a la venta padre si ya está vinculada.
     *
     * Si se modifica el precio o la cantidad de una línea de una venta YA
     * persistida, Doctrine no dispara Sale::onPreUpdate (no cambió ningún
     * campo de la venta) y totalAmount quedaría desfasado. Este aviso
     * garantiza la invariante "Sale.totalAmount === Σ líneas" también
     * durante modificaciones.
     */
    private function notifySaleTotal(): void
    {
        if (isset($this->sale)) {
            $this->sale->recalculateTotalAmount();
        }
    }

    public function getTotalLine(): string
    {
        return $this->totalLine;
    }

    public function getUsdRateSnapshot(): ?string
    {
        return $this->usdRateSnapshot;
    }

    public function setUsdRateSnapshot(?string $v): self
    {
        $this->usdRateSnapshot = $v;
        return $this;
    }

    public function getRealUnitCostSnapshot(): string
    {
        return $this->realUnitCostSnapshot;
    }

    public function setRealUnitCostSnapshot(string $v): self
    {
        $this->realUnitCostSnapshot = $v;
        // [FIX-1] Eager: costRecovered y grossProfitLine derivan del costo
        // snapshot, así que también se recalculan aquí. Sin esto, quien los
        // lea antes del flush (SaleService, para decidir la ganancia
        // reconocida) vería los valores iniciales '0.00'.
        $this->recalculateDerived();
        return $this;
    }

    public function getCurrentCostSnapshot(): string
    {
        return $this->currentCostSnapshot;
    }

    public function setCurrentCostSnapshot(string $v): self
    {
        $this->currentCostSnapshot = $v;
        return $this;
    }

    public function getCostRecovered(): string
    {
        return $this->costRecovered;
    }

    public function getGrossProfitLine(): string
    {
        return $this->grossProfitLine;
    }

    public function getRecognizedProfitLine(): string
    {
        return $this->recognizedProfitLine;
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
