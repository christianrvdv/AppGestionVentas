<?php

declare(strict_types=1);

namespace App\Tests\Functional\Investment;

use App\Entity\Investment;
use App\Entity\InvestmentExpense;
use App\Service\Investment\InvestmentExpenseService;
use App\Service\TenantContext;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fase 3b: alta y baja de gastos de inversión desde el detalle.
 *
 * Reglas que ejercitan:
 *   - Solo inversiones abiertas sin confirmar reciben gastos.
 *   - El gasto prorrateable recalcula allocatedExpense/realUnitCost.
 *   - El gasto NO prorrateable no toca el costo real unitario.
 *   - CSRF plano (stateless, token id "submit") => 403.
 *   - Errores de form => errores de campo, no flash.
 */
final class InvestmentExpenseUiTest extends FunctionalTestCase
{
    public function testShowRendersExpenseForm(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user, null, 'Sin gastos');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('form[action$="/gastos"]'));
        $form = $crawler->selectButton('Agregar gasto')->form();
        $this->assertCount(
            1,
            $crawler->filter('select[name="investment_expense_form[category]"] option[value="TRANSPORT"]')
        );
        $this->assertTrue(
            $form['investment_expense_form[isAllocated]']->hasValue(),
            'El checkbox de prorrateo debe venir tildado por defecto.'
        );
        $this->assertSelectorTextContains('#total-gastos', '0,00');
        $this->assertSelectorNotExists('form[action*="/eliminar"]');
    }

    public function testAddAllocatedExpenseRecalculatesItemAllocatedExpense(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Yerba', 'YER-1');
        $this->addItemToInvestment($investment, $product, 2, '100.00');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Agregar gasto')->form();
        $client->submit($form, [
            'investment_expense_form' => [
                'category' => 'TRANSPORT',
                'amount' => '90.00',
                'expenseDate' => '2026-01-15',
                'description' => 'Flete del proveedor',
                'isAllocated' => true,
            ],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Gasto agregado.');
        $this->assertSelectorTextContains('#total-gastos', '90,00');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(1, $reloaded->getExpenses());

        $expense = $reloaded->getExpenses()->first();
        $this->assertInstanceOf(InvestmentExpense::class, $expense);
        $this->assertSame('TRANSPORT', $expense->getCategory());
        $this->assertDecimalEquals('90.00', $expense->getAmount());
        $this->assertTrue($expense->isAllocated());
        $this->assertSame('Flete del proveedor', $expense->getDescription());
        $this->assertSame('2026-01-15', $expense->getExpenseDate()->format('Y-m-d'));

        $item = $reloaded->getItems()->first();
        $this->assertDecimalEquals('90.00', $item->getAllocatedExpense());
        $this->assertDecimalEquals('145.00', $item->getRealUnitCost());
    }

    public function testAddNonAllocatedExpenseDoesNotChangeItemAllocatedExpense(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Azúcar', 'AZU-1');
        $this->addItemToInvestment($investment, $product, 2, '100.00');

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Agregar gasto')->form();
        $client->submit($form, [
            'investment_expense_form' => [
                'category' => 'OTHER',
                'amount' => '60.00',
                'expenseDate' => '2026-01-15',
                'description' => 'Gasto general del local',
                'isAllocated' => false,
            ],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Gasto agregado.');
        $this->assertSelectorTextContains('#total-gastos', '60,00');
        $this->assertSelectorTextContains('#tabla-gastos', 'No prorrateable');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(1, $reloaded->getExpenses());

        $expense = $reloaded->getExpenses()->first();
        $this->assertInstanceOf(InvestmentExpense::class, $expense);
        $this->assertFalse($expense->isAllocated());

        $item = $reloaded->getItems()->first();
        $this->assertDecimalEquals('0.00', $item->getAllocatedExpense());
        $this->assertDecimalEquals('100.00', $item->getRealUnitCost());
    }

    public function testRemoveExpenseLowersTotalsAndRecalculatesItems(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Yerba', 'YER-1');
        $this->addItemToInvestment($investment, $product, 2, '100.00');

        $allocated = $this->seedExpense($investment, '100.00', true);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertSelectorTextContains('#total-gastos', '100,00');

        // Segundo gasto desde la UI: el re-prorrateo corre sobre allocations
        // ya persistidas (regresión de uniq_allocation_expense_item).
        $crawler = $client->submit($crawler->selectButton('Agregar gasto')->form(), [
            'investment_expense_form' => [
                'category' => InvestmentExpense::CATEGORY_STORAGE,
                'amount' => '40.00',
                'expenseDate' => '2026-01-16',
                'description' => 'Estantería',
                'isAllocated' => false,
            ],
        ]);
        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Gasto agregado.');
        $this->assertSelectorTextContains('#total-gastos', '140,00');
        $this->assertDecimalEquals('100.00', $this->itemOf($investment)->getAllocatedExpense());

        $form = $crawler->filter(sprintf('form[action*="/gastos/%d/eliminar"]', $allocated->getId()))->form();
        $client->submit($form);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Gasto eliminado.');
        $this->assertSelectorTextContains('#total-gastos', '40,00');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(1, $reloaded->getExpenses());

        $remaining = $reloaded->getExpenses()->first();
        $this->assertInstanceOf(InvestmentExpense::class, $remaining);
        $this->assertDecimalEquals('40.00', $remaining->getAmount());

        $item = $reloaded->getItems()->first();
        $this->assertDecimalEquals('0.00', $item->getAllocatedExpense());
        $this->assertDecimalEquals('100.00', $item->getRealUnitCost());
    }

    public function testAddExpenseToConfirmedInvestmentShowsErrorFlash(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $product = $this->seedProduct($tenant, 'Yerba', 'YER-1');
        $this->addItemToInvestment($investment, $product);

        $context = $client->getContainer()->get(TenantContext::class);
        $context->setTenantId($tenant->getId());
        $client->getContainer()
            ->get(\App\Service\Investment\InvestmentConfirmationService::class)
            ->confirm($investment);
        $this->em->flush();
        $context->clear();

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $this->assertSelectorNotExists('form[action$="/gastos"]');

        $client->request('POST', '/inversiones/'.$investment->getId().'/gastos', [
            'investment_expense_form' => [
                '_token' => $this->formCsrfToken($client),
                'category' => 'FOOD',
                'amount' => '25.00',
                'expenseDate' => '2026-01-15',
                'description' => 'Intento tardío',
                'isAllocated' => true,
            ],
        ]);

        $this->assertResponseRedirects('/inversiones/'.$investment->getId());
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'abiertas sin confirmar');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(0, $reloaded->getExpenses());
    }

    public function testRemoveRejectsInvalidCsrfToken(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);
        $expense = $this->seedExpense($investment, '30.00');

        $this->loginAsDemoAdmin($client);
        $client->request(
            'POST',
            sprintf('/inversiones/%d/gastos/%d/eliminar', $investment->getId(), $expense->getId()),
            ['_csrf_token' => 'no-es-un-token']
        );

        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(1, $reloaded->getExpenses());
    }

    public function testAddRejectsNonPositiveAmountWithFieldError(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        $investment = $this->seedInvestment($user);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/inversiones/'.$investment->getId());
        $form = $crawler->selectButton('Agregar gasto')->form();
        $client->submit($form, [
            'investment_expense_form' => [
                'category' => 'FOOD',
                'amount' => '0',
                'expenseDate' => '2026-01-15',
                'description' => null,
                'isAllocated' => true,
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[role="alert"]', 'El monto debe ser mayor a cero.');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());
        $this->assertCount(0, $reloaded->getExpenses());
    }

    /**
     * Gasto de prueba creado por el servicio de dominio (mismo camino que
     * usa la UI), seteando el TenantContext que SettingsService exige.
     */
    private function seedExpense(
        Investment $investment,
        string $amount,
        bool $isAllocated = true,
        string $category = InvestmentExpense::CATEGORY_TRANSPORT,
    ): InvestmentExpense {
        $context = static::getContainer()->get(TenantContext::class);
        $previous = $context->getTenantId();
        $context->setTenantId($investment->getTenant()->getId());

        try {
            $expense = static::getContainer()
                ->get(InvestmentExpenseService::class)
                ->addExpense(
                    $investment,
                    $category,
                    $amount,
                    new \DateTimeImmutable('2026-01-15'),
                    'Gasto de prueba',
                    $isAllocated,
                );
            $this->em->flush();

            return $expense;
        } finally {
            $context->setTenantId($previous);
        }
    }

    private function itemOf(Investment $investment): \App\Entity\InvestmentItem
    {
        $this->em->clear();
        $reloaded = $this->em->getRepository(Investment::class)->find($investment->getId());

        return $reloaded->getItems()->first();
    }

    /**
     * Token de form "submit" (stateless, ver config/packages/csrf.yaml):
     * el mismo sirve para todos los forms y para el CSRF plano.
     */
    private function formCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/inversiones/nueva');
        $token = $crawler->filter('form input[name$="[_token]"]')->attr('value');

        $this->assertNotNull($token);

        return $token;
    }
}
