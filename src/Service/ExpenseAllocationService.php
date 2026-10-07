<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Investment;
use App\Entity\InvestmentExpense;
use App\Entity\InvestmentItem;
use App\Entity\ExpenseAllocation;
use App\Repository\Contract\ExpenseAllocationRepositoryInterface;
use Doctrine\Common\Collections\Collection;

/**
 * Prorrateo de gastos entre ítems de una inversión.
 *
 * ALGORITMO (ADR-0002):
 *   1. Filtra gastos con isAllocated = true.
 *   2. Calcula subtotal de mercancía por ítem: itemSubtotal = unitCost * quantity.
 *   3. Si totalMerchandise = 0, no prorratea (limpia allocations existentes).
 *   4. Para cada ítem (ordenados por ID para determinismo):
 *        allocationPct = (itemSubtotal / totalMerchandise) * 100
 *        allocatedAmount = round(expenseAmount * allocationPct / 100, scale)
 *      El ÚLTIMO ítem absorbe el remanente para que Σ === expenseAmount exacto.
 *   5. Vincula vía $expense->addAllocation() (cascade persist + orphanRemoval).
 *   6. Actualiza InvestmentItem.allocatedExpense sumando EN MEMORIA.
 *
 * [FIX-2] Idempotencia: en lugar de DQL DELETE (que no ve entidades en memoria),
 *         se usa $expense->getAllocations()->clear(). Con orphanRemoval: true
 *         en InvestmentExpense::$allocations, Doctrine elimina las viejas al
 *         flush. Esto evita duplicados cuando el servicio se invoca dos veces
 *         en el mismo request (ej. addItem → addExpense → allocateForInvestment
 *         dos veces).
 */
final class ExpenseAllocationService
{
    public function __construct(
        private readonly ExpenseAllocationRepositoryInterface $allocationRepository,
        private readonly SettingsService                      $settingsService
    )
    {
    }

    public function allocateForInvestment(Investment $investment): void
    {
        $items = $investment->getItems();

        if ($items->isEmpty()) {
            return;
        }

        $scale = $this->settingsService->getCurrencyScale();

        // Calcula subtotales por ítem
        $itemSubtotals = [];
        $totalMerchandise = '0.00';
        foreach ($items as $item) {
            $qty = (string)$item->getQuantity();
            $subtotal = bcmul($item->getUnitCost(), $qty, $scale);
            $itemSubtotals[$item->getId()] = $subtotal;
            $totalMerchandise = bcadd($totalMerchandise, $subtotal, $scale);
        }

        if (bccomp($totalMerchandise, '0', $scale) === 0) {
            // Sin base: limpiar allocations de gastos prorrateables
            // [FIX-2] Usar clear() en lugar de DQL DELETE
            foreach ($investment->getExpenses() as $expense) {
                if ($expense->isAllocated()) {
                    $expense->getAllocations()->clear();
                }
            }
            $this->resetAllocatedExpenseOnItems($items);
            return;
        }

        foreach ($investment->getExpenses() as $expense) {
            if (!$expense->isAllocated()) {
                continue;
            }
            $this->allocateExpense($expense, $items, $itemSubtotals, $totalMerchandise, $scale);
        }

        $this->recalculateItemAllocatedExpenses($items, $investment);
    }

    private function allocateExpense(
        InvestmentExpense $expense,
        Collection        $items,
        array             $itemSubtotals,
        string            $totalMerchandise,
        int               $scale
    ): void
    {
        $expenseAmount = $expense->getAmount();

        // [FIX-2] Idempotencia en memoria: clear() + orphanRemoval: true.
        // NO usar deleteByExpense (DQL DELETE) porque no afecta entidades
        // cargadas en memoria y produce duplicados en el mismo request.
        $expense->getAllocations()->clear();

        $itemArray = $items->toArray();
        $count = count($itemArray);
        $allocatedSum = '0.00';

        for ($i = 0; $i < $count; ++$i) {
            $item = $itemArray[$i];
            $itemId = $item->getId();
            $isLast = ($i === $count - 1);

            $subtotal = $itemSubtotals[$itemId] ?? '0.00';

            $allocationPct = bccomp($totalMerchandise, '0', $scale) !== 0
                ? bcdiv(bcmul($subtotal, '100', $scale + 4), $totalMerchandise, $scale + 4)
                : '0.00';

            if ($isLast) {
                $allocatedAmount = bcsub($expenseAmount, $allocatedSum, $scale);
            } else {
                $allocatedAmount = bcdiv(bcmul($expenseAmount, $allocationPct, $scale + 4), '100', $scale);
                $allocatedSum = bcadd($allocatedSum, $allocatedAmount, $scale);
            }

            $allocation = new ExpenseAllocation();
            $allocation->setTenant($expense->getTenant());
            $allocation->setInvestmentExpense($expense);
            $allocation->setInvestmentItem($item);
            $allocation->setAllocatedAmount($allocatedAmount);
            $allocation->setAllocationPct($allocationPct);

            $expense->addAllocation($allocation);
        }
    }

    /**
     * Recalcula allocatedExpense sumando EN MEMORIA (sin consultar DB).
     */
    private function recalculateItemAllocatedExpenses(Collection $items, Investment $investment): void
    {
        $sumsByItem = [];
        foreach ($items as $item) {
            $sumsByItem[$item->getId()] = '0.00';
        }

        foreach ($investment->getExpenses() as $expense) {
            if (!$expense->isAllocated()) {
                continue;
            }
            foreach ($expense->getAllocations() as $allocation) {
                $itemId = $allocation->getInvestmentItem()->getId();
                if (isset($sumsByItem[$itemId])) {
                    $sumsByItem[$itemId] = bcadd($sumsByItem[$itemId], $allocation->getAllocatedAmount(), 2);
                }
            }
        }

        $usdRate = $investment->getUsdRateSnapshot();
        foreach ($items as $item) {
            $sum = $sumsByItem[$item->getId()] ?? '0.00';
            $item->setAllocatedExpense($sum);

            if ($usdRate !== null && bccomp($usdRate, '0', 4) > 0) {
                $item->setAllocatedExpenseUsd(bcdiv($sum, $usdRate, 4));
            } else {
                $item->setAllocatedExpenseUsd(null);
            }
        }
    }

    private function resetAllocatedExpenseOnItems(Collection $items): void
    {
        foreach ($items as $item) {
            $item->setAllocatedExpense('0.00');
            $item->setAllocatedExpenseUsd(null);
        }
    }

    public function sumAllocatedByItem(InvestmentItem $item): string
    {
        return $this->allocationRepository->sumByItem($item->getId(), $item->getTenant()->getId());
    }
}
