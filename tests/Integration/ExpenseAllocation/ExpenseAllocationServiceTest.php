<?php

namespace App\Tests\Integration\ExpenseAllocation;

use App\Entity\ExpenseAllocation;
use App\Entity\Investment;
use App\Entity\InvestmentExpense;
use App\Entity\InvestmentItem;
use App\Entity\Tenant;
use App\Service\ExpenseAllocationService;
use App\Service\Investment\InvestmentItemService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * [FIX-3] Prorrateo indexado por INSTANCIA (spl_object_id), nunca por ID.
 *
 * Los items de una inversión en borrador todavía no tienen ID: getId()
 * lanza Error (propiedad sin inicializar) o, si se coercía a null, PHP lo
 * convertía en la clave '' del array, con lo que dos items nuevos
 * compartían clave y el segundo pisaba al primero.
 *
 * Los tests trabajan SIN flush intermedio para reproducir exactamente ese
 * escenario (addItem -> addExpense -> allocateForInvestment en la misma
 * request).
 */
final class ExpenseAllocationServiceTest extends IntegrationTestCase
{
    private ExpenseAllocationService $allocationService;

    private InvestmentItemService $itemService;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->allocationService = $container->get(ExpenseAllocationService::class);
        $this->itemService = $container->get(InvestmentItemService::class);
    }

    public function test_prorratea_gasto_entre_items_que_todavia_no_tienen_id(): void
    {
        [$tenant, $investment, $item1, $item2] = $this->investmentWithTwoDraftItems();

        $this->assertFalse(
            (new \ReflectionProperty(InvestmentItem::class, 'id'))->isInitialized($item1),
            'Precondición: los items deben estar sin persistir (id no inicializado).'
        );

        $expense = $this->addAllocatedExpense($investment, $tenant, '100.00');
        $this->allocationService->allocateForInvestment($investment);

        $this->assertCount(2, $expense->getAllocations(), 'Debe haber un allocation por item, no uno solo.');

        // Subtotales: item1 = 1 * 10.00 = 10.00, item2 = 3 * 10.00 = 30.00 (total 40.00)
        // 100.00 * 10/40 = 25.00 ; el último absorbe el resto = 75.00
        $this->assertDecimalEquals('25.00', $item1->getAllocatedExpense());
        $this->assertDecimalEquals('75.00', $item2->getAllocatedExpense());

        $sum = '0.00';
        foreach ($expense->getAllocations() as $allocation) {
            $sum = bcadd($sum, $allocation->getAllocatedAmount(), 2);
        }
        $this->assertDecimalEquals('100.00', $sum, 'La suma de allocations debe ser exactamente el monto del gasto.');

        $allocatedFor = array_map(
            static fn (ExpenseAllocation $a): int => spl_object_id($a->getInvestmentItem()),
            $expense->getAllocations()->toArray()
        );
        $this->assertContains(spl_object_id($item1), $allocatedFor);
        $this->assertContains(spl_object_id($item2), $allocatedFor);
    }

    public function test_es_idempotente_y_no_duplica_allocations(): void
    {
        [$tenant, $investment, $item1, $item2] = $this->investmentWithTwoDraftItems();

        $expense = $this->addAllocatedExpense($investment, $tenant, '100.00');
        $this->allocationService->allocateForInvestment($investment);
        $this->allocationService->allocateForInvestment($investment);

        $this->assertCount(2, $expense->getAllocations(), 'Segunda pasada no debe duplicar allocations.');
        $this->assertDecimalEquals('25.00', $item1->getAllocatedExpense());
        $this->assertDecimalEquals('75.00', $item2->getAllocatedExpense());
    }

    public function test_prorrateo_se_persiste_y_sobrevive_al_reload(): void
    {
        [$tenant, $investment, $item1, $item2] = $this->investmentWithTwoDraftItems();

        $this->addAllocatedExpense($investment, $tenant, '100.00');
        $this->allocationService->allocateForInvestment($investment);

        $this->em->flush();

        $item1Id = $item1->getId();
        $item2Id = $item2->getId();

        $this->em->clear();

        /** @var InvestmentItem $reloaded1 */
        $reloaded1 = $this->em->getRepository(InvestmentItem::class)->find($item1Id);
        /** @var InvestmentItem $reloaded2 */
        $reloaded2 = $this->em->getRepository(InvestmentItem::class)->find($item2Id);

        $this->assertDecimalEquals('25.00', $reloaded1->getAllocatedExpense());
        $this->assertDecimalEquals('75.00', $reloaded2->getAllocatedExpense());

        $persistedAllocations = $this->em->getRepository(ExpenseAllocation::class)->findAll();
        $this->assertCount(2, $persistedAllocations);
    }

    /**
     * @return array{0: Tenant, 1: Investment, 2: InvestmentItem, 3: InvestmentItem}
     */
    private function investmentWithTwoDraftItems(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant, 'Producto');

        // Items SIN flush: es el escenario del bug (ids no inicializados).
        $item1 = $this->itemService->addItem($investment, $product, 1, '10.00');
        $item2 = $this->itemService->addItem($investment, $product, 3, '10.00');

        return [$tenant, $investment, $item1, $item2];
    }

    private function addAllocatedExpense(Investment $investment, Tenant $tenant, string $amount): InvestmentExpense
    {
        $expense = new InvestmentExpense();
        $expense->setTenant($tenant);
        $expense->setInvestment($investment);
        $expense->setCategory(InvestmentExpense::CATEGORY_TRANSPORT);
        $expense->setAmount($amount);
        $expense->setExpenseDate(new \DateTimeImmutable('2026-01-02'));
        $expense->setIsAllocated(true);

        $investment->addExpense($expense);

        return $expense;
    }
}
