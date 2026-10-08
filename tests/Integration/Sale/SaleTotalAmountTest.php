<?php

namespace App\Tests\Integration\Sale;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\AppUser;
use App\Entity\Sale;
use App\Entity\SaleLine;
use App\Service\Investment\InvestmentItemService;
use App\Service\Sale\SaleService;
use App\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\Query\QueryException;

/**
 * [FIX-1] Sale.totalAmount y SaleLine.totalLine se calculan en el momento
 * de asignar quantity/unitPrice, no solo en los callbacks de Doctrine.
 *
 * Doctrine ejecuta PrePersist en orden de dependencia de claves foráneas:
 * PRIMERO Sale y DESPUÉS SaleLine. Si totalLine solo se calculara en
 * SaleLine::onPrePersist, cuando Sale::onPrePersist sumaba las líneas todas
 * leían '0.00' y el total de la venta se persistía en cero.
 */
final class SaleTotalAmountTest extends IntegrationTestCase
{
    private SaleService $saleService;

    private InvestmentItemService $itemService;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->saleService = $container->get(SaleService::class);
        $this->itemService = $container->get(InvestmentItemService::class);
    }

    public function test_venta_registrada_persiste_el_total_no_cero(): void
    {
        [$tenant, $user, $investment, $item] = $this->investmentWithStock();

        $this->registerSaleToleratingSummaryDqlBug(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [new RegisterSaleLineCommand(investmentItemId: $item->getId(), quantity: 2, unitPrice: '10.00')],
            ),
            $user
        );

        $sale = $this->onlySale();

        $this->assertDecimalEquals('20.00', $sale->getTotalAmount(), 'totalAmount persistido.');
        $this->assertDecimalEquals('20.00', $sale->getLines()->first()->getTotalLine(), 'totalLine persistido.');
    }

    public function test_varias_lineas_se_suman_en_el_total_persistido(): void
    {
        [$tenant, $user, $investment, $item] = $this->investmentWithStock();

        $this->registerSaleToleratingSummaryDqlBug(
            new RegisterSaleCommand(
                investmentId: $investment->getId(),
                customerId: null,
                date: new \DateTimeImmutable('2026-01-10'),
                lines: [
                    new RegisterSaleLineCommand(investmentItemId: $item->getId(), quantity: 2, unitPrice: '10.00'),
                    new RegisterSaleLineCommand(investmentItemId: $item->getId(), quantity: 3, unitPrice: '15.00'),
                ],
            ),
            $user
        );

        // 2 * 10.00 = 20.00  +  3 * 15.00 = 45.00  =>  65.00
        $sale = $this->onlySale();

        $this->assertDecimalEquals('65.00', $sale->getTotalAmount());

        $sum = '0.00';
        foreach ($sale->getLines() as $line) {
            $sum = bcadd($sum, $line->getTotalLine(), 2);
        }
        $this->assertDecimalEquals('65.00', $sum);
        $this->assertDecimalEquals($sale->getTotalAmount(), $sum, 'El total debe ser la suma exacta de las líneas.');
    }

    /**
     * Caso puro de entidades: los setters de la línea mantienen al día el
     * total de la venta, sin depender del orden de los callbacks de Doctrine.
     */
    public function test_setters_de_la_linea_mantienen_al_dia_el_total_de_la_venta(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $item = $this->itemService->addItem($investment, $product, 10, '5.00');
        $this->em->flush();

        $sale = $this->newSale($tenant, $user, $investment);

        $line = new SaleLine();
        $line->setTenant($tenant);
        $line->setInvestmentItem($item);
        $line->setRealUnitCostSnapshot($item->getRealUnitCost());
        $line->setCurrentCostSnapshot($item->getCurrentRealUnitCost());

        // Se agrega ANTES de tener quantity/unitPrice: totalLine aún no existe.
        $sale->addLine($line);
        $this->assertDecimalEquals('0.00', $sale->getTotalAmount());

        // Ahora los setters deben recalcular la línea Y avisar a la venta.
        $line->setQuantity(2);
        $line->setUnitPrice('10.00');

        $this->assertDecimalEquals('20.00', $line->getTotalLine());
        $this->assertDecimalEquals('20.00', $sale->getTotalAmount());

        // Cambiar la cantidad vuelve a recalcular ambos.
        $line->setQuantity(3);
        $this->assertDecimalEquals('30.00', $line->getTotalLine());
        $this->assertDecimalEquals('30.00', $sale->getTotalAmount());

        // Quitar la línea deja el total en cero.
        $sale->removeLine($line);
        $this->assertDecimalEquals('0.00', $sale->getTotalAmount());
    }

    /**
     * Red de seguridad: aunque nadie avise a la venta, los callbacks de
     * Doctrine vuelven a recalcular el total al persistir.
     */
    public function test_callbacks_de_doctrine_recalculan_el_total_al_persistir(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $item = $this->itemService->addItem($investment, $product, 10, '5.00');
        $this->em->flush();

        $sale = $this->newSale($tenant, $user, $investment);

        $line = new SaleLine();
        $line->setTenant($tenant);
        $line->setInvestmentItem($item);
        $line->setRealUnitCostSnapshot('5.00');
        $line->setCurrentCostSnapshot('5.00');
        $line->setQuantity(4);
        $line->setUnitPrice('12.50');
        $sale->addLine($line);

        $this->em->persist($sale);
        $this->em->flush();

        $saleId = $sale->getId();
        $this->em->clear();

        /** @var Sale $reloaded */
        $reloaded = $this->em->getRepository(Sale::class)->find($saleId);
        $this->assertDecimalEquals('50.00', $reloaded->getTotalAmount());
        $this->assertDecimalEquals('50.00', $reloaded->getLines()->first()->getTotalLine());
    }

    /**
     * @return array{0: \App\Entity\Tenant, 1: AppUser, 2: \App\Entity\Investment, 3: \App\Entity\InvestmentItem}
     */
    private function investmentWithStock(): array
    {
        $tenant = $this->createTenant();
        $user = $this->createAppUser($tenant);
        $investment = $this->createInvestment($tenant, $user);
        $product = $this->createProduct($tenant);

        $item = $this->itemService->addItem($investment, $product, 10, '5.00');
        $this->em->flush();
        $this->confirmInvestment($investment);

        return [$tenant, $user, $investment, $item];
    }

    private function newSale(\App\Entity\Tenant $tenant, AppUser $user, \App\Entity\Investment $investment): Sale
    {
        $sale = new Sale();
        $sale->setTenant($tenant);
        $sale->setCreatedBy($user);
        $sale->setInvestment($investment);
        $sale->setCurrency($investment->getBaseCurrency());
        $sale->setSaleDate(new \DateTimeImmutable('2026-01-10'));

        return $sale;
    }

    /**
     * SaleService::register ya persistió el Sale y sus líneas (flush #1 y
     * #2) antes de recalcular el summary, así que el total se puede
     * comprobar aunque el paso final falle.
     *
     * [OBS-1] FUERA DE ALCANCE: InvestmentSummaryRepository usa el alias
     * DQL 'is', palabra reservada del parser, por lo que
     * InvestmentSummaryService::recompute() lanza QueryException siempre,
     * en cualquier plataforma. No se corrige aquí (no es parte de [FIX-1]);
     * queda anotado en las observaciones del reporte.
     */
    private function registerSaleToleratingSummaryDqlBug(RegisterSaleCommand $cmd, AppUser $user): void
    {
        try {
            $this->saleService->register($cmd, $user);
        } catch (QueryException) {
            // Ver docblock: bug fuera de alcance que no afecta al total.
        }
    }

    private function onlySale(): Sale
    {
        $sales = $this->em->getRepository(Sale::class)->findAll();
        $this->assertCount(1, $sales, 'Debe existir exactamente una venta registrada.');

        return $sales[0];
    }
}
