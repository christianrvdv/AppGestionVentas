<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Product;
use App\Exception\Domain\InvestmentAlreadyConfirmedException;
use App\Exception\Domain\TenantMismatchException;
use App\Service\ExpenseAllocationService;
use App\Service\SettingsService;

/**
 * Gestión de ítems de una inversión (agregar, validar).
 *
 * REGLAS:
 *   - Solo inversiones NO confirmadas (estado OPEN).
 *   - quantity > 0, unitCost >= 0.
 *   - Al agregar: dispara prorrateo de gastos Y recálculo de costos/precios.
 */
final class InvestmentItemService
{
    public function __construct(
        private readonly ExpenseAllocationService $allocationService,
        private readonly InvestmentItemCalculatorService $calculatorService,
        private readonly SettingsService $settingsService
    ) {}

    /**
     * Agrega un ítem a la inversión.
     *
     * @throws InvestmentAlreadyConfirmedException Si la inversión ya está confirmada
     * @throws \InvalidArgumentException           Si quantity <= 0 o unitCost < 0
     */
    public function addItem(
        Investment $investment,
        Product $product,
        int $quantity,
        string $unitCost
    ): InvestmentItem {
        if ($investment->isConfirmed()) {
            throw InvestmentAlreadyConfirmedException::forInvestment($investment->getId());
        }

        // C.6: Validar que el producto pertenece al mismo tenant
        if ($product->getTenant()->getId() !== $investment->getTenant()->getId()) {
            throw TenantMismatchException::forEntities(
                'Product',
                $investment->getTenant()->getId(),
                $product->getTenant()->getId()
            );
        }

        if ($quantity <= 0) {
            throw new \InvalidArgumentException('quantity debe ser > 0');
        }
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $unitCost)) {
            throw new \InvalidArgumentException('unitCost debe ser numérico válido');
        }
        if (bccomp($unitCost, '0', $this->settingsService->getCurrencyScale()) < 0) {
            throw new \InvalidArgumentException('unitCost no puede ser negativo');
        }

        $item = new InvestmentItem();
        $item->setTenant($investment->getTenant());
        $item->setInvestment($investment);
        $item->setProduct($product);
        $item->setQuantity($quantity);
        $item->setUnitCost($unitCost);

        // unitCostUsd si hay tasa en la inversión
        $usdRate = $investment->getUsdRateSnapshot();
        if ($usdRate !== null && bccomp($usdRate, '0', 4) > 0) {
            $item->setUnitCostUsd(bcdiv($unitCost, $usdRate, 4));
        }

        $investment->addItem($item);

        // Prorratea gastos y recalcula TODOS los ítems (incluye el nuevo)
        $this->allocationService->allocateForInvestment($investment);
        $this->calculatorService->recalculateAll($investment);

        return $item;
    }
}