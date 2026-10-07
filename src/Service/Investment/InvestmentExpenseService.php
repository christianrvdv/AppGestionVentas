<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentExpense;
use App\Exception\Domain\InvestmentAlreadyConfirmedException;
use App\Service\ExpenseAllocationService;
use App\Service\InvestmentItemCalculatorService;
use App\Service\SettingsService;

/**
 * Gestión de gastos de una inversión (agregar, eliminar).
 *
 * REGLAS:
 *   - Solo inversiones NO confirmadas (estado OPEN).
 *   - Al agregar/eliminar: dispara re-prorrateo Y recálculo de costos/precios.
 */
final class InvestmentExpenseService
{
    public function __construct(
        private readonly ExpenseAllocationService $allocationService,
        private readonly InvestmentItemCalculatorService $calculatorService,
        private readonly SettingsService $settingsService
    ) {}

    /**
     * Agrega un gasto a la inversión.
     *
     * @param string             $category     Categoría (TRANSPORT, FOOD, BATHROOM, SNACK, STORAGE, OTHER)
     * @param string             $amount       Monto >= 0
     * @param \DateTimeImmutable $date         Fecha del gasto
     * @param string|null        $description  Descripción opcional
     * @param bool               $isAllocated  Si true, se prorratea entre ítems
     *
     * @throws InvestmentAlreadyConfirmedException Si la inversión ya está confirmada
     * @throws \InvalidArgumentException           Si amount < 0 o categoría inválida
     */
    public function addExpense(
        Investment $investment,
        string $category,
        string $amount,
        \DateTimeImmutable $date,
        ?string $description = null,
        bool $isAllocated = true
    ): InvestmentExpense {
        if ($investment->isConfirmed()) {
            throw InvestmentAlreadyConfirmedException::forInvestment($investment->getId());
        }

        $validCategories = [
            InvestmentExpense::CATEGORY_TRANSPORT,
            InvestmentExpense::CATEGORY_FOOD,
            InvestmentExpense::CATEGORY_BATHROOM,
            InvestmentExpense::CATEGORY_SNACK,
            InvestmentExpense::CATEGORY_STORAGE,
            InvestmentExpense::CATEGORY_OTHER,
        ];
        if (!in_array($category, $validCategories, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Categoría inválida: %s. Válidas: %s',
                $category,
                implode(', ', $validCategories)
            ));
        }

        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $amount)) {
            throw new \InvalidArgumentException('amount debe ser numérico válido');
        }
        if (bccomp($amount, '0', $this->settingsService->getCurrencyScale()) < 0) {
            throw new \InvalidArgumentException('amount no puede ser negativo');
        }

        $expense = new InvestmentExpense();
        $expense->setTenant($investment->getTenant());
        $expense->setInvestment($investment);
        $expense->setCategory($category);
        $expense->setDescription($description);
        $expense->setAmount($amount);
        $expense->setExpenseDate($date);
        $expense->setIsAllocated($isAllocated);

        // USD snapshot del gasto
        $usdRate = $investment->getUsdRateSnapshot();
        if ($usdRate !== null && bccomp($usdRate, '0', 4) > 0) {
            $expense->setUsdRateSnapshot($usdRate);
            $expense->setAmountUsd(bcdiv($amount, $usdRate, 4));
        }

        $investment->addExpense($expense);

        // Re-prorratea TODOS los gastos y recalcula ítems
        $this->allocationService->allocateForInvestment($investment);
        $this->calculatorService->recalculateAll($investment);

        return $expense;
    }

    /**
     * Elimina un gasto de la inversión.
     *
     * @throws InvestmentAlreadyConfirmedException Si la inversión ya está confirmada
     */
    public function removeExpense(InvestmentExpense $expense): void
    {
        $investment = $expense->getInvestment();

        if ($investment->isConfirmed()) {
            throw InvestmentAlreadyConfirmedException::forInvestment($investment->getId());
        }

        $investment->removeExpense($expense);

        // Re-prorratea gastos restantes y recalcula ítems
        $this->allocationService->allocateForInvestment($investment);
        $this->calculatorService->recalculateAll($investment);
    }
}