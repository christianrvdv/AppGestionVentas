<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\ExpenseAllocation;

/**
 * Puerto de persistencia para la asignación de gastos a ítems.
 * Implementación Doctrine: App\Repository\ExpenseAllocationRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface ExpenseAllocationRepositoryInterface
{
    /**
     * @return ExpenseAllocation[]
     */
    public function findByInvestmentItem(int $itemId, int $tenantId): array;

    /**
     * @return ExpenseAllocation[]
     */
    public function findByExpense(int $expenseId, int $tenantId): array;

    public function sumByExpense(int $expenseId, int $tenantId): string;

    public function sumByItem(int $itemId, int $tenantId): string;

    public function deleteByExpense(int $expenseId, int $tenantId): int;

    /**
     * @return ExpenseAllocation[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array;

    public function deleteByItem(int $itemId, int $tenantId): int;
}
