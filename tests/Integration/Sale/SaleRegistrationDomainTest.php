<?php

declare(strict_types=1);

namespace App\Tests\Integration\Sale;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Exception\Domain\InsufficientStockException;
use App\Exception\Domain\ItemNotInInvestmentException;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Service\Sale\SaleService;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Reglas de dominio que el form de venta (Fase 3c.2) debe respetar
 * SIEMPRE, incluso si el navegador manda algo que la UI no permitiría.
 *
 * Se prueban sobre SaleService (el único punto de entrada) y no sobre el
 * controller: lo que se protege es la invariante, no la capa web.
 */
final class SaleRegistrationDomainTest extends IntegrationTestCase
{
    private SaleService $saleService;

    private ?\App\Entity\Tenant $tenant = null;

    private ?\App\Entity\AppUser $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saleService = static::getContainer()->get(SaleService::class);
    }

    /**
     * [FIX-1] El stock se descuenta EN MEMORIA entre líneas del mismo
     * ítem: los InventoryMovements de la venta no existen hasta después
     * del flush, así que sin este control dos líneas del mismo ítem
     * podrían vender más unidades de las que hay.
     */
    public function test_dos_lineas_del_mismo_item_no_pueden_exceder_el_stock(): void
    {
        [, $user, $investment, $item] = $this->investmentWithStock(10);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('Stock insuficiente');

        try {
            $this->saleService->register(
                new RegisterSaleCommand(
                    investmentId: $investment->getId(),
                    customerId: null,
                    date: new \DateTimeImmutable('2026-01-10'),
                    lines: [
                        new RegisterSaleLineCommand($item->getId(), 6, '150.00'),
                        new RegisterSaleLineCommand($item->getId(), 6, '150.00'),
                    ]
                ),
                $user
            );
        } finally {
            $this->em->clear();
            $this->assertCount(
                0,
                $this->em->getRepository(\App\Entity\Sale::class)->findAll(),
                'Ninguna venta debe haber quedado persistida.'
            );
        }
    }

    public function test_un_item_de_otra_inversion_se_rechaza(): void
    {
        [, $user, $investment] = $this->investmentWithStock(10);
        [, , $otherInvestment, $otherItem] = $this->investmentWithStock(10, 'INV-OTHER');

        $this->expectException(ItemNotInInvestmentException::class);
        $this->expectExceptionMessage('pertenece a inversión');

        $this->saleService->register(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [new RegisterSaleLineCommand($otherItem->getId(), 1, '150.00')]
            ),
            $user
        );

        $this->em->clear();
        $this->assertCount(0, $this->em->getRepository(\App\Entity\Sale::class)->findAll());
        $this->assertSame(
            10,
            static::getContainer()->get(InventoryMovementRepositoryInterface::class)
                ->getQuantityDeltaSum($otherItem->getId(), $otherInvestment->getTenant()->getId()),
            'El stock de la otra inversión no se toca.'
        );
    }

    /**
     * Inversión confirmada con stock. Todas las del test comparten tenant
     * y usuario (SaleService resuelve la inversión contra el tenant activo,
     * que es el de la última que se creó).
     *
     * @return array{0: \App\Entity\Tenant, 1: \App\Entity\AppUser, 2: \App\Entity\Investment, 3: \App\Entity\InvestmentItem}
     */
    private function investmentWithStock(int $quantity = 10, string $code = 'INV-TEST'): array
    {
        $tenant = $this->tenant ??= $this->createTenant();
        $user = $this->user ??= $this->createAppUser($tenant);

        $investment = $this->createInvestment($tenant, $user, $code);
        $product = $this->createProduct($tenant, 'Producto '.$code);

        $item = static::getContainer()->get(\App\Service\Investment\InvestmentItemService::class)
            ->addItem($investment, $product, $quantity, '100.00');
        $this->em->flush();
        $this->confirmInvestment($investment);

        return [$tenant, $user, $investment, $item];
    }
}
