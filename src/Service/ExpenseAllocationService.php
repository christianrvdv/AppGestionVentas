<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Investment;
use App\Entity\InvestmentExpense;
use App\Entity\InvestmentItem;
use App\Entity\ExpenseAllocation;
use Doctrine\Common\Collections\Collection;

/**
 * Prorrateo de gastos entre ítems de una inversión.
 *
 * ALGORITMO (ADR-0002):
 *   1. Filtra gastos con isAllocated = true.
 *   2. Calcula subtotal de mercancía por ítem: itemSubtotal = unitCost * quantity.
 *   3. Si totalMerchandise = 0, no prorratea (limpia allocations existentes).
 *   4. Para cada ítem (en el orden de la colección):
 *        allocationPct = (itemSubtotal / totalMerchandise) * 100
 *        allocatedAmount = round(expenseAmount * allocationPct / 100, scale)
 *      El ÚLTIMO ítem absorbe el remanente para que Σ === expenseAmount exacto.
 *   5. Vincula vía $expense->addAllocation() (cascade persist + orphanRemoval).
 *   6. Actualiza InvestmentItem.allocatedExpense sumando EN MEMORIA.
 *
 * [FIX-2] Idempotencia: en lugar de DQL DELETE (que no ve entidades en memoria),
 *         la rama sin base usa $expense->getAllocations()->clear() (orphanRemoval
 *         las borra en el flush). Evita duplicados cuando el servicio se invoca
 *         dos veces en el mismo request (ej. addItem -> addExpense ->
 *         allocateForInvestment).
 *
 * [FIX-3] Claves de array por INSTANCIA, nunca por ID: los ítems de una
 *         inversión en borrador aún no tienen ID (getId() no está inicializado
 *         y PHP convertiría null en la clave ''), con lo que dos ítems nuevos
 *         compartían clave y el segundo pisaba al primero. Se usa
 *         spl_object_id($item), único para cada instancia en memoria.
 *         Vale para allocateForInvestment(), allocateExpense() y
 *         recalculateItemAllocatedExpenses().
 *
 * [FIX-4] Re-prorrateo con allocations YA persistidas (un segundo gasto, un ítem
 *         agregado después): allocateExpense() reutiliza cada fila existente en
 *         vez de clear() + recrear, porque Doctrine inserta antes de borrar y
 *         la clave única uniq_allocation_expense_item rechazaría el INSERT.
 */
final class ExpenseAllocationService
{
    public function __construct(
        private readonly SettingsService $settingsService
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
        // [FIX-3] Clave = spl_object_id($item): getId() es null en ítems
        // nuevos y PHP mapea null a la clave '', colisionando todos.
        $itemSubtotals = [];
        $totalMerchandise = '0.00';
        foreach ($items as $item) {
            $qty = (string)$item->getQuantity();
            $subtotal = bcmul($item->getUnitCost(), $qty, $scale);
            $itemSubtotals[spl_object_id($item)] = $subtotal;
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

    /**
     * @param Collection<int, InvestmentItem> $items
     * @param array<int, string>              $itemSubtotals Clave = spl_object_id del ítem
     */
    private function allocateExpense(
        InvestmentExpense $expense,
        Collection        $items,
        array             $itemSubtotals,
        string            $totalMerchandise,
        int               $scale
    ): void
    {
        $expenseAmount = $expense->getAmount();

        // [FIX-4] NO clear() + recrear: Doctrine ejecuta los INSERT antes que
        // los DELETE (UnitOfWork::commit), así que reemplazar allocations ya
        // persistidas inserta la fila nueva (expense, item) mientras la vieja
        // sigue en la tabla y revienta en uniq_allocation_expense_item. Era
        // latente porque solo se cubrió el caso "todo en el mismo flush".
        // Se REUTILIZAN las allocations existentes por instancia de ítem
        // (identity map, igual que en recalculateItemAllocatedExpenses) y solo
        // se actualizan sus importes; las que sobran sí se retiran y Doctrine
        // las borra sin colisionar (claves distintas a las insertadas).
        $reusable = [];
        foreach ($expense->getAllocations() as $allocation) {
            $reusable[spl_object_id($allocation->getInvestmentItem())] = $allocation;
        }

        $itemArray = array_values($items->toArray());
        $count = count($itemArray);
        $allocatedSum = '0.00';

        for ($i = 0; $i < $count; ++$i) {
            $item = $itemArray[$i];
            $isLast = ($i === $count - 1);

            // [FIX-3] Misma clave que en allocateForInvestment(): instancia, no ID.
            $subtotal = $itemSubtotals[spl_object_id($item)] ?? '0.00';

            $allocationPct = bccomp($totalMerchandise, '0', $scale) !== 0
                ? bcdiv(bcmul($subtotal, '100', $scale + 4), $totalMerchandise, $scale + 4)
                : '0.00';

            if ($isLast) {
                $allocatedAmount = bcsub($expenseAmount, $allocatedSum, $scale);
            } else {
                $allocatedAmount = bcdiv(bcmul($expenseAmount, $allocationPct, $scale + 4), '100', $scale);
                $allocatedSum = bcadd($allocatedSum, $allocatedAmount, $scale);
            }

            $key = spl_object_id($item);
            $allocation = $reusable[$key] ?? null;

            if ($allocation instanceof ExpenseAllocation) {
                $allocation->setAllocatedAmount($allocatedAmount);
                $allocation->setAllocationPct($allocationPct);
                unset($reusable[$key]);

                continue;
            }

            $allocation = new ExpenseAllocation();
            $allocation->setTenant($expense->getTenant());
            $allocation->setInvestmentExpense($expense);
            $allocation->setInvestmentItem($item);
            $allocation->setAllocatedAmount($allocatedAmount);
            $allocation->setAllocationPct($allocationPct);

            $expense->addAllocation($allocation);
        }

        // Sobras: allocations de ítems que ya no están en la inversión (o
        // duplicados heredados). Se insertaron claves para otros ítems, así
        // que el DELETE no choca con ningún INSERT de este flush.
        foreach ($reusable as $stale) {
            $expense->removeAllocation($stale);
        }
    }

    /**
     * Recalcula allocatedExpense sumando EN MEMORIA (sin consultar DB).
     *
     * [FIX-3] El mapa se indexa por spl_object_id: los allocations referencian
     * la MISMA instancia de InvestmentItem que vive en la colección de la
     * inversión (EntityManager con identity map), de modo que la búsqueda por
     * instancia es exacta incluso con ítems sin ID persistido.
     *
     * @param Collection<int, InvestmentItem> $items
     */
    private function recalculateItemAllocatedExpenses(Collection $items, Investment $investment): void
    {
        $sumsByItem = [];
        foreach ($items as $item) {
            $sumsByItem[spl_object_id($item)] = '0.00';
        }

        foreach ($investment->getExpenses() as $expense) {
            if (!$expense->isAllocated()) {
                continue;
            }
            foreach ($expense->getAllocations() as $allocation) {
                $itemKey = spl_object_id($allocation->getInvestmentItem());
                if (isset($sumsByItem[$itemKey])) {
                    $sumsByItem[$itemKey] = bcadd($sumsByItem[$itemKey], $allocation->getAllocatedAmount(), 2);
                }
            }
        }

        $usdRate = $investment->getUsdRateSnapshot();
        foreach ($items as $item) {
            $sum = $sumsByItem[spl_object_id($item)] ?? '0.00';
            $item->setAllocatedExpense($sum);

            if ($usdRate !== null && bccomp($usdRate, '0', 4) > 0) {
                $item->setAllocatedExpenseUsd(bcdiv($sum, $usdRate, 4));
            } else {
                $item->setAllocatedExpenseUsd(null);
            }
        }
    }

    /**
     * [FIX-3] No usa getId() como clave (solo recorre la colección),
     * por lo que funciona igual con ítems nuevos que con persistidos.
     *
     * @param Collection<int, InvestmentItem> $items
     */
    private function resetAllocatedExpenseOnItems(Collection $items): void
    {
        foreach ($items as $item) {
            $item->setAllocatedExpense('0.00');
            $item->setAllocatedExpenseUsd(null);
        }
    }
}
