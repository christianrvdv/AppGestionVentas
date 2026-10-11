<?php

declare(strict_types=1);

namespace App\Tests\Functional\Sale;

use App\Entity\InventoryMovement;
use App\Entity\InvestmentSummary;
use App\Entity\Sale;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Fase 3c.2: anulación de una venta desde el detalle
 * (POST -> SaleVoidService::void).
 *
 * Reglas que ejercitan:
 *   - CSRF plano (stateless, token id "submit") => 403 con token inválido.
 *   - La anulación devuelve el stock y recalcula el summary.
 *   - Una venta con pagos NO se anula (SaleHasPaymentsException).
 *   - El motivo es obligatorio (lo pide el dominio para la auditoría).
 */
final class SaleVoidTest extends FunctionalTestCase
{
    public function testVoidReturnsStockAndRecalculatesSummary(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, null, null, quantity: 4, unitPrice: '150.00');

        $this->loginAsDemoAdmin($client);
        $client->request(
            'POST',
            '/ventas/'.$sale->getId().'/anular',
            ['_csrf_token' => $this->plainCsrfToken($client, $sale), 'reason' => 'El cliente devolvió todo']
        );

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'Venta anulada.');
        $this->assertSelectorTextContains('#venta-anulada', 'El cliente devolvió todo');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Sale::class)->find($sale->getId());
        $this->assertNotNull($reloaded->getVoidedAt(), 'la venta queda marcada como anulada.');
        $this->assertSame('El cliente devolvió todo', $reloaded->getVoidReason());
        $this->assertSame($user->getId(), $reloaded->getVoidedBy()?->getId());

        // El stock vuelve: 10 compradas + 4 devueltas.
        $this->assertSame(10, $this->stockOf($item->getId(), $tenant->getId()));
        $this->assertTrue($this->movementExists($tenant->getId(), $item->getId(), InventoryMovement::TYPE_CUSTOMER_RETURN));

        $summary = $this->summaryOf($investment->getId(), $tenant->getId());
        $this->assertDecimalEquals('0.00', $summary->getTotalRecovered(), 'una venta anulada no recupera nada.');
        $this->assertDecimalEquals('0.00', $summary->getTotalRecognizedProfit());
        $this->assertDecimalEquals('1000.00', $summary->getTotalPending(), 'la inversión vuelve a estar pendiente.');
        $this->assertSame(0, $summary->getUnitsSold());
        $this->assertSame(10, $summary->getUnitsRemaining());
    }

    public function testVoidWithPaymentsShowsErrorFlash(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, null, null, quantity: 4, unitPrice: '150.00');
        $this->seedPayment($sale, '300.00', 'CASH');

        $this->loginAsDemoAdmin($client);
        $client->request(
            'POST',
            '/ventas/'.$sale->getId().'/anular',
            ['_csrf_token' => $this->plainCsrfToken($client, $sale), 'reason' => 'Intento con cobro aplicado']
        );

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'tiene pagos aplicados');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Sale::class)->find($sale->getId());
        $this->assertNull($reloaded->getVoidedAt(), 'la venta sigue activa.');
        $this->assertSame(6, $this->stockOf($item->getId(), $tenant->getId()), 'el stock no se devuelve.');
        $this->assertSame(
            0,
            $this->countMovements($tenant->getId(), $item->getId(), InventoryMovement::TYPE_CUSTOMER_RETURN),
            'no se genera movimiento de devolución.'
        );

        $summary = $this->summaryOf($investment->getId(), $tenant->getId());
        $this->assertDecimalEquals('400.00', $summary->getTotalRecovered());
    }

    public function testVoidRejectsInvalidCsrfToken(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user, 'tenant' => $tenant] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment, 'item' => $item] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, null, null, quantity: 4, unitPrice: '150.00');

        $this->loginAsDemoAdmin($client);
        $client->request(
            'POST',
            '/ventas/'.$sale->getId().'/anular',
            ['_csrf_token' => 'no-es-un-token', 'reason' => 'Ataque CSRF']
        );

        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Sale::class)->find($sale->getId());
        $this->assertNull($reloaded->getVoidedAt(), 'la venta no se anuló.');
        $this->assertSame(6, $this->stockOf($item->getId(), $tenant->getId()));
    }

    public function testVoidRequiresReason(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, null, null, quantity: 4, unitPrice: '150.00');

        $this->loginAsDemoAdmin($client);
        $client->request(
            'POST',
            '/ventas/'.$sale->getId().'/anular',
            ['_csrf_token' => $this->plainCsrfToken($client, $sale), 'reason' => '   ']
        );

        $this->assertResponseRedirects('/ventas/'.$sale->getId());
        $client->followRedirect();
        $this->assertSelectorTextContains('[role="alert"]', 'motivo de la anulación');

        $this->em->clear();
        $reloaded = $this->em->getRepository(Sale::class)->find($sale->getId());
        $this->assertNull($reloaded->getVoidedAt(), 'la venta sigue activa.');
    }

    public function testVoidedSaleDoesNotOfferTheVoidForm(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedVoidedSale($investment, $user, 'Error de tipeo', quantity: 2);

        $this->loginAsDemoAdmin($client);
        $crawler = $client->request('GET', '/ventas/'.$sale->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form[action$="/anular"]');
        $this->assertSelectorTextContains('#venta-anulada', 'Error de tipeo');
    }

    public function testVoidReturns404ForUnknownSale(): void
    {
        $client = $this->createSharedKernelClient();
        ['user' => $user] = $this->seedDemoTenantAndAdmin();
        ['investment' => $investment] = $this->seedConfirmedInvestmentWithStock($user);
        $sale = $this->seedSale($investment, $user, null, null, quantity: 1);

        $this->loginAsDemoAdmin($client);
        // El token sale de una venta real; el id del POST no existe.
        $client->request(
            'POST',
            '/ventas/999999/anular',
            ['_csrf_token' => $this->plainCsrfToken($client, $sale), 'reason' => 'No existe']
        );

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * Token CSRF plano del form de anulación del detalle (token id
     * "submit", stateless: el mismo valor sirve para cualquier POST).
     */
    private function plainCsrfToken(KernelBrowser $client, Sale $sale): string
    {
        $crawler = $client->request('GET', '/ventas/'.$sale->getId());
        $token = $crawler->filter('form[action$="/anular"] input[name="_csrf_token"]')->attr('value');

        $this->assertNotNull($token);

        return $token;
    }

    private function stockOf(int $itemId, int $tenantId): int
    {
        return static::getContainer()
            ->get(InventoryMovementRepositoryInterface::class)
            ->getQuantityDeltaSum($itemId, $tenantId);
    }

    private function movementExists(int $tenantId, int $itemId, string $movementType): bool
    {
        return $this->countMovements($tenantId, $itemId, $movementType) > 0;
    }

    private function countMovements(int $tenantId, int $itemId, string $movementType): int
    {
        $movements = static::getContainer()
            ->get(InventoryMovementRepositoryInterface::class)
            ->findByItem($itemId, $tenantId);

        $count = 0;
        foreach ($movements as $movement) {
            if ($movementType === $movement->getMovementType()) {
                ++$count;
            }
        }

        return $count;
    }

    private function summaryOf(int $investmentId, int $tenantId): InvestmentSummary
    {
        $this->em->clear();

        $summary = static::getContainer()
            ->get(InvestmentSummaryRepositoryInterface::class)
            ->findByInvestment($investmentId, $tenantId);

        $this->assertInstanceOf(InvestmentSummary::class, $summary, 'La inversión debe tener summary.');

        return $summary;
    }
}
