<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\InvestmentExpense;

/**
 * Puerto de persistencia para gastos de inversión.
 * Implementación Doctrine: App\Repository\InvestmentExpenseRepository.
 *
 * Invariante: toda consulta filtra por tenant_id. Ningún método puede
 * devolver datos de otro tenant.
 */
interface InvestmentExpenseRepositoryInterface
{
    public function sumExpensesByInvestment(int $investmentId, int $tenantId): string;

    public function sumExpensesUsdByInvestment(int $investmentId, int $tenantId): string;

    /**
     * @return InvestmentExpense[]
     */
    public function findByInvestment(int $investmentId, int $tenantId): array;

    /**
     * @return InvestmentExpense[]
     */
    public function findAllocatedByInvestment(int $investmentId, int $tenantId): array;

    public function sumAllocatedByInvestment(int $investmentId, int $tenantId): string;

    public function sumNonAllocatedByInvestment(int $investmentId, int $tenantId): string;
}
