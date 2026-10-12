<?php

declare(strict_types=1);

namespace App\Tests\Unit\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Repository\Contract\AppSettingRepositoryInterface;
use App\Repository\TenantRepository;
use App\Service\Investment\InvestmentItemCalculatorService;
use App\Service\SettingsService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests directos de InvestmentItemCalculatorService (Fase 7.5).
 *
 * El servicio está en producción desde Fase 1 y nunca tuvo test propio:
 * lo que se protege aquí es la fórmula de costo real, la cascada de
 * márgenes y el contrato C.3 (recalculate() NO toca current*).
 *
 * Sin BD: entidades instanciadas en memoria y SettingsService armado con
 * un repo de settings falso (SettingsService es final, no es mockeable,
 * pero sus dependencias acá no se usan: el repo devuelve los settings y
 * el resto queda sin tocar).
 */
final class InvestmentItemCalculatorServiceTest extends TestCase
{
    private const TENANT_ID = 1;

    /**
     * Fórmula: realUnitCost = (unitCost * qty + allocatedExpense) / qty.
     * (100 * 4 + 50) / 4 = 112.50. Sin tasa USD en la inversión,
     * realUnitCostUsd queda null.
     */
    public function test_real_unit_cost_incluye_el_gasto_asignado(): void
    {
        $item = $this->item(quantity: 4, unitCost: '100.00', allocatedExpense: '50.00');

        $this->service()->recalculate($item);

        $this->assertSame('112.50', $item->getRealUnitCost());
        $this->assertNull($item->getRealUnitCostUsd(), 'Sin usdRateSnapshot en la inversión, el costo USD es null.');
    }

    /**
     * Fórmula: suggestedPrice = realUnitCost * (1 + marginPct / 100).
     * Con margen de settings 35%: 100.00 * 1.35 = 135.00.
     */
    public function test_suggested_price_aplica_el_margen_resuelto(): void
    {
        $item = $this->item(quantity: 2, unitCost: '100.00', allocatedExpense: '0.00');

        $this->service(['default_margin_pct' => '35.00'])
            ->recalculate($item);

        $this->assertSame('135.00', $item->getSuggestedPrice());
        $this->assertSame('35.00', $item->getSuggestedMarginPct(), 'El margen efectivo resuelto queda registrado en el ítem.');
    }

    /**
     * Cascada de márgenes, nivel 1: el margen del ÍTEM (si > 0) gana sobre
     * el producto y sobre settings.
     */
    public function test_margen_del_item_tiene_prioridad_en_la_cascada(): void
    {
        $product = new Product();
        $product->setDefaultMarginPct('25.00');
        $item = $this->item(quantity: 2, unitCost: '100.00', product: $product);
        $item->setSuggestedMarginPct('55.00');

        $this->service(['default_margin_pct' => '35.00'])->recalculate($item);

        $this->assertSame('55.00', $item->getSuggestedMarginPct());
        $this->assertSame('155.00', $item->getSuggestedPrice(), '100.00 * 1.55.');
    }

    /**
     * Cascada, nivel 2: sin margen en el ítem, manda el default del
     * producto por sobre settings.
     */
    public function test_margen_del_producto_se_usa_cuando_el_item_no_tiene(): void
    {
        $product = new Product();
        $product->setDefaultMarginPct('25.00');
        $item = $this->item(quantity: 2, unitCost: '100.00', product: $product);

        $this->service(['default_margin_pct' => '35.00'])->recalculate($item);

        $this->assertSame('25.00', $item->getSuggestedMarginPct());
        $this->assertSame('125.00', $item->getSuggestedPrice(), '100.00 * 1.25.');
    }

    /**
     * Cascada, nivel 3: sin margen en ítem ni producto, se usa el de
     * settings (y su default hardcodeado de 40% si la clave no existe).
     */
    public function test_margen_de_settings_es_el_ultimo_recurso(): void
    {
        $item = $this->item(quantity: 2, unitCost: '100.00');

        $this->service(['default_margin_pct' => '30.00'])->recalculate($item);
        $this->assertSame('30.00', $item->getSuggestedMarginPct());
        $this->assertSame('130.00', $item->getSuggestedPrice());

        $itemSinSetting = $this->item(quantity: 2, unitCost: '100.00');
        $this->service([])->recalculate($itemSinSetting);
        $this->assertSame('40.00', $itemSinSetting->getSuggestedMarginPct(), 'Default hardcodeado: 40%.');
        $this->assertSame('140.00', $itemSinSetting->getSuggestedPrice());
    }

    /**
     * Contrato C.3: recalculate() NO inicializa ni actualiza current*.
     * currentRealUnitCost solo lo tocan confirm() y revalue(); si
     * recalculate() lo sobrescribiera, un ítem revaluado perdería su
     * costo "actual" de venta sin pasar por revaluation.
     */
    public function test_recalculate_no_actualiza_current_real_unit_cost(): void
    {
        $item = $this->item(quantity: 2, unitCost: '100.00', allocatedExpense: '0.00');
        $item->setCurrentRealUnitCost('99.99');
        $item->setCurrentSuggestedPrice('88.88');

        $this->service([])->recalculate($item);

        $this->assertSame('99.99', $item->getCurrentRealUnitCost(), 'currentRealUnitCost no debe cambiar en recalculate().');
        $this->assertSame('88.88', $item->getCurrentSuggestedPrice(), 'currentSuggestedPrice tampoco.');
        $this->assertSame('100.00', $item->getRealUnitCost(), 'realUnitCost (el no-current) sí se recalcula.');
    }

    /**
     * Ítem sin allocation: allocatedExpense = 0 ⇒ realUnitCost = unitCost.
     */
    public function test_item_sin_allocation_deja_el_costo_unitario_igual_al_unit_cost(): void
    {
        $item = $this->item(quantity: 7, unitCost: '43.21', allocatedExpense: '0.00');

        $this->service([])->recalculate($item);

        $this->assertSame('0.00', $item->getAllocatedExpense());
        $this->assertSame('43.21', $item->getRealUnitCost());
    }

    /**
     * Ítem armado en memoria (sin persistir). La moneda la toma de la
     * inversión; el producto por defecto no tiene margen.
     */
    private function item(
        int      $quantity,
        string   $unitCost,
        string   $allocatedExpense = '0.00',
        ?Product $product = null
    ): InvestmentItem {
        $investment = new Investment();
        $investment->setBaseCurrency('USD');

        $item = new InvestmentItem();
        $item->setInvestment($investment);
        $item->setProduct($product ?? new Product());
        $item->setQuantity($quantity);
        $item->setUnitCost($unitCost);
        $item->setAllocatedExpense($allocatedExpense);

        return $item;
    }

    /**
     * SettingsService real (no es mockeable: final) con el repo de
     * settings sustituido por los valores del test.
     *
     * @param array<string, string> $settings
     */
    private function service(array $settings = []): InvestmentItemCalculatorService
    {
        $tenantContext = new TenantContext();
        $tenantContext->setTenantId(self::TENANT_ID);

        $repository = $this->createMock(AppSettingRepositoryInterface::class);
        $repository
            ->expects($this->atLeastOnce())
            ->method('findAllKeyedByKey')
            ->with(self::TENANT_ID)
            ->willReturn($settings);

        $settingsService = new SettingsService(
            $repository,
            $tenantContext,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(TenantRepository::class)
        );

        return new InvestmentItemCalculatorService($settingsService);
    }
}
