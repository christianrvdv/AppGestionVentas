<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Service\SettingsService;
use App\Service\Money\Money;

/**
 * Calculadora de costos reales y precios sugeridos por ítem.
 *
 * FÓRMULAS:
 *   realUnitCost       = (unitCost * quantity + allocatedExpense) / quantity
 *   realUnitCostUsd    = realUnitCost / usdRateSnapshot          (si aplica)
 *   suggestedPrice     = realUnitCost * (1 + marginPct / 100)
 *
 * MARGEN EN CASCADA (prioridad):
 *   1. item.suggestedMarginPct (si > 0)
 *   2. product.defaultMarginPct (si > 0)
 *   3. settings.defaultMarginPct (default 40%)
 *
 * INICIALIZACIÓN AL CONFIRMAR (InvestmentConfirmationService):
 *   - currentRealUnitCost   := realUnitCost   (si estaba en '0.00')
 *   - currentSuggestedPrice := suggestedPrice (si estaba en '0.00')
 *
 * REVALUACIÓN (InvestmentRevaluationService):
 *   - currentRealUnitCost   := nuevo realUnitCost
 *   - currentSuggestedPrice := nuevo suggestedPrice
 *   (solo si inversión en OPEN o PARTIAL)
 *
 * IMPORTANTE: recalculate() NO inicializa current* (C.3 fix).
 *   current* se setea solo en confirm() y revalue().
 *
 * NO PERSISTE: solo modifica la entidad. El llamador hace flush.
 */
final class InvestmentItemCalculatorService
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {}

    /**
     * Recalcula un solo ítem.
     */
    public function recalculate(InvestmentItem $item): void
    {
        $investment = $item->getInvestment();
        $scale = $this->settingsService->getCurrencyScale();
        $currency = $investment->getBaseCurrency();

        // 1. realUnitCost = (unitCost * quantity + allocatedExpense) / quantity
        $qty = (string)$item->getQuantity();
        $unitCost = $item->getUnitCost();
        $allocatedExpense = $item->getAllocatedExpense();

        $unitCostMoney = Money::of($unitCost, $currency, $scale);
        $allocatedMoney = Money::of($allocatedExpense, $currency, $scale);
        $merchandiseTotal = $unitCostMoney->mul($qty);
        $totalCost = $merchandiseTotal->add($allocatedMoney);
        $realUnitCost = $totalCost->div($qty)->getAmount();

        $item->setRealUnitCost($realUnitCost);

        // 2. realUnitCostUsd (si hay tasa en la inversión)
        $usdRateSnapshot = $investment->getUsdRateSnapshot();
        if ($usdRateSnapshot !== null && bccomp($usdRateSnapshot, '0', 4) > 0) {
            $item->setRealUnitCostUsd(bcdiv($realUnitCost, $usdRateSnapshot, 4));
        } else {
            $item->setRealUnitCostUsd(null);
        }

        // 3. suggestedPrice = realUnitCost * (1 + marginPct / 100)
        $marginPct = $this->resolveMarginPct($item);
        $multiplier = bcadd('1', bcdiv($marginPct, '100', $scale + 4), $scale + 4);
        $realCostMoney = Money::of($realUnitCost, $currency, $scale);
        $suggestedPrice = $realCostMoney->mul($multiplier)->getAmount();

        $item->setSuggestedPrice($suggestedPrice);
        $item->setSuggestedMarginPct($marginPct);

        // 4. NO inicializar current* aquí (C.3).
        //    current* se inicializa SOLO en InvestmentConfirmationService::confirm()
        //    y se actualiza en InvestmentRevaluationService::revalue().
    }

    /**
     * Recalcula todos los ítems de una inversión.
     * Primero prorratea gastos (para tener allocatedExpense actualizado).
     */
    public function recalculateAll(Investment $investment): void
    {
        foreach ($investment->getItems() as $item) {
            $this->recalculate($item);
        }
    }

    /**
     * Resuelve el margen porcentual aplicable en cascada.
     *
     * @return string Margin percentage (ej. "40.00")
     *
     * NOTA (C.4 / ADR-0008): `suggestedMarginPct` en la entidad es el margen EFECTIVO RESUELTO
     * (no un override del usuario). La cascada es:
     *   1. item.suggestedMarginPct (si > 0) -> ya resuelto previamente
     *   2. product.defaultMarginPct (si > 0)
     *   3. settings.defaultMarginPct (default 40%)
     *
     * Si el usuario quiere forzar un margen, se necesitaría un campo separado
     * `marginOverridePct` (nullable). Deuda técnica documentada en ADR-0008.
     */
    private function resolveMarginPct(InvestmentItem $item): string
    {
        $scale = $this->settingsService->getCurrencyScale();

        // 1. Margen del ítem (si seteado explícitamente > 0)
        $itemMargin = $item->getSuggestedMarginPct();
        if (bccomp($itemMargin, '0', $scale) > 0) {
            return $itemMargin;
        }

        // 2. Margen por defecto del producto
        /** @var Product $product */
        $product = $item->getProduct();
        $productMargin = $product->getDefaultMarginPct();
        if ($productMargin !== null && bccomp($productMargin, '0', $scale) > 0) {
            return $productMargin;
        }

        // 3. Margen por defecto del tenant (settings)
        return $this->settingsService->getDefaultMarginPct();
    }
}