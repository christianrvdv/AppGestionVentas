<?php

namespace App\Tests\Integration\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentExpense;
use App\Entity\Tenant;
use App\Exception\Domain\AllocationMismatchException;
use App\Service\ExpenseAllocationService;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentItemService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [FIX-4] validateExpenseAllocations() valida EN MEMORIA sobre
 * $expense->getAllocations().
 *
 * Antes consultaba la BD (sumByExpense) y además la clase tenía mal el
 * import de InvestmentItemCalculatorService, con lo que no llegaba a
 * construirse. El flujo típico crea los items/gastos en memoria y
 * confirma sin flush intermedio: la consulta devolvía vacío y se lanzaba
 * AllocationMismatchException falso.
 */
final class InvestmentConfirmationServiceTest extends IntegrationTestCase
{
    private InvestmentConfirmationService $confirmationService;

    private InvestmentItemService $itemService;

    private ExpenseAllocationService $allocationService;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->confirmationService = $container->get(InvestmentConfirmationService::class);
        $this->itemService = $container->get(InvestmentItemService::class);
        $this->allocationService = $container->get(ExpenseAllocationService::class);
    }

    public function test_confirma_con_gasto_prorrateado_que_nunca_llego_a_bd(): void
    {
        [$tenant, $investment] = $this->investmentWithItems();

        $expense = $this->addAllocatedExpense($investment, $tenant, '60.00');
        $this->allocationService->allocateForInvestment($investment);

        // El gasto y sus allocations NO se han flusheado: solo existen en
        // memoria. Es el caso exacto que antes disparaba el error falso.
        $summary = $this->confirmationService->confirm($investment);
        $this->em->flush();

        $this->assertTrue($investment->isConfirmed());
        $this->assertNotNull($summary->getId());
        $this->assertCount(2, $expense->getAllocations());

        $expenseId = $expense->getId();
        $this->em->clear();

        /** @var InvestmentExpense $reloaded */
        $reloaded = $this->em->getRepository(InvestmentExpense::class)->find($expenseId);
        $this->assertCount(2, $reloaded->getAllocations());
        $sum = '0.00';
        foreach ($reloaded->getAllocations() as $allocation) {
            $sum = bcadd($sum, $allocation->getAllocatedAmount(), 2);
        }
        $this->assertDecimalEquals('60.00', $sum);
    }

    public function test_rechaza_gasto_prorrateado_sin_allocations(): void
    {
        [$tenant, $investment] = $this->investmentWithItems();

        $expense = $this->addAllocatedExpense($investment, $tenant, '60.00');
        $this->em->flush();

        $this->expectException(AllocationMismatchException::class);
        $this->confirmationService->confirm($investment);
    }

    public function test_acepta_gasto_no_prorrateable_sin_allocations(): void
    {
        [$tenant, $investment] = $this->investmentWithItems();

        $expense = $this->addAllocatedExpense($investment, $tenant, '60.00', isAllocated: false);
        $this->em->flush();

        $summary = $this->confirmationService->confirm($investment);
        $this->em->flush();

        $this->assertTrue($investment->isConfirmed());
        $this->assertNotNull($summary->getId());
        $this->assertCount(0, $expense->getAllocations());
    }

    /**
     * @return array{0: Tenant, 1: Investment}
     */
    private function investmentWithItems(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $this->itemService->addItem($investment, $product, 2, '10.00');
        $this->itemService->addItem($investment, $product, 4, '10.00');

        // confirm() crea movimientos PURCHASE con referenceId = item id,
        // así que los items sí deben estar persistidos.
        $this->em->flush();

        return [$tenant, $investment];
    }

    private function addAllocatedExpense(
        Investment $investment,
        Tenant $tenant,
        string $amount,
        bool $isAllocated = true
    ): InvestmentExpense {
        $expense = new InvestmentExpense();
        $expense->setTenant($tenant);
        $expense->setInvestment($investment);
        $expense->setCategory(InvestmentExpense::CATEGORY_TRANSPORT);
        $expense->setAmount($amount);
        $expense->setExpenseDate(new \DateTimeImmutable('2026-01-02'));
        $expense->setIsAllocated($isAllocated);

        $investment->addExpense($expense);

        return $expense;
    }
}
