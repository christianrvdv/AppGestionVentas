<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\ItemCostRevaluation;
use App\Entity\UsdRate;
use App\Exception\Domain\TenantMismatchException;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Service\SettingsService;
use App\Service\Money\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Revaluación de costos y precios por cambio de tasa USD.
 *
 * SOLO para inversiones en estado OPEN o PARTIAL.
 *
 * [FIX-3] Persiste cada ItemCostRevaluation explícitamente vía EntityManager
 *         (la colección InvestmentItem::$revaluations no tiene cascade: persist).
 *         Flush tras el loop para que InvestmentSummaryService::recompute()
 *         vea los nuevos registros al consultar sumGainLossByInvestment().
 *
 * POLÍTICA DE FLUSH: este servicio flushea internamente dos veces
 *   (tras revaluaciones y tras recompute) para garantizar consistencia.
 */
final class InvestmentRevaluationService
{
    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movementRepository,
        private readonly InvestmentItemCalculatorService      $calculatorService,
        private readonly InvestmentSummaryService             $summaryService,
        private readonly SettingsService                      $settingsService,
        private readonly EntityManagerInterface               $entityManager
    )
    {
    }

    public function revalue(Investment $investment, UsdRate $newRate, AppUser $by): void
    {
        // Validar tenant cruzado
        if ($newRate->getTenant()->getId() !== $investment->getTenant()->getId()) {
            throw TenantMismatchException::forEntities(
                'UsdRate',
                $investment->getTenant()->getId(),
                $newRate->getTenant()->getId()
            );
        }

        $status = $investment->getStatus();
        if (!in_array($status, [Investment::STATUS_OPEN, Investment::STATUS_PARTIAL], true)) {
            throw new \LogicException(sprintf(
                'No se puede revaluar inversión en estado %s. Solo OPEN o PARTIAL.',
                $status
            ));
        }

        $oldRate = $investment->getCurrentUsdRate();
        if ($oldRate === null) {
            throw new \LogicException('La inversión no tiene tasa USD previa para revaluar.');
        }

        if (bccomp($oldRate, $newRate->getRate(), 4) === 0) {
            return; // Sin cambios
        }

        foreach ($investment->getItems() as $item) {
            $stock = $this->getCurrentStock($item);
            if ($stock <= 0) {
                continue;
            }
            $this->revalueItem($item, $oldRate, $newRate, $stock, $by);
        }

        // [FIX-3] Flush para que recompute() lea las revaluaciones desde DB
        $this->entityManager->flush();

        // Actualizar inversión
        $investment->setCurrentUsdRate($newRate->getRate());
        $investment->setLastRevaluedAt(new \DateTimeImmutable());

        // Recalcular summary
        $this->summaryService->recompute($investment);

        // [FIX-3] Flush final para persistir el summary actualizado
        $this->entityManager->flush();
    }

    private function revalueItem(
        InvestmentItem $item,
        string         $oldRate,
        UsdRate        $newRate,
        int            $quantitySnapshot,
        AppUser        $by
    ): void
    {
        $oldRealUnitCost = $item->getCurrentRealUnitCost();
        $oldSuggestedPrice = $item->getCurrentSuggestedPrice();
        $currency = $item->getInvestment()->getBaseCurrency();
        $scale = $this->settingsService->getCurrencyScale();

        // newRealUnitCost = oldRealUnitCost * (newRate / oldRate)
        $rateRatio = bcdiv($newRate->getRate(), $oldRate, 6);
        $oldCostMoney = Money::of($oldRealUnitCost, $currency, $scale);
        $newRealUnitCost = $oldCostMoney->mul($rateRatio)->getAmount();

        // Cascada de margen (coherente con InvestmentItemCalculatorService)
        $marginPct = $this->resolveMarginPct($item);

        $multiplier = bcadd('1', bcdiv($marginPct, '100', 6), 6);
        $newCostMoney = Money::of($newRealUnitCost, $currency, $scale);
        $newSuggestedPrice = $newCostMoney->mul($multiplier)->getAmount();

        // Revaluation gain/loss = qty * (newCost - oldCost)
        // Positivo = costo subió = pérdida latente (ADR-0006)
        $costDiff = bcsub($newRealUnitCost, $oldRealUnitCost, 4);
        $revaluationGainLoss = bcmul($costDiff, (string)$quantitySnapshot, 2);

        $revaluation = new ItemCostRevaluation();
        $revaluation->setTenant($item->getTenant());
        $revaluation->setInvestmentItem($item);
        $revaluation->setUsdRate($newRate);
        $revaluation->setTriggeredBy($by);
        $revaluation->setSource(ItemCostRevaluation::SOURCE_MANUAL);
        $revaluation->setOldRate($oldRate);
        $revaluation->setNewRate($newRate->getRate());
        $revaluation->setOldRealUnitCost($oldRealUnitCost);
        $revaluation->setNewRealUnitCost($newRealUnitCost);
        $revaluation->setOldSuggestedPrice($oldSuggestedPrice);
        $revaluation->setNewSuggestedPrice($newSuggestedPrice);
        $revaluation->setQuantitySnapshot($quantitySnapshot);
        $revaluation->setRevaluationGainLoss($revaluationGainLoss);
        $revaluation->setReason(sprintf(
            'Revaluación por cambio de tasa USD: %s → %s',
            $oldRate,
            $newRate->getRate()
        ));

        // [FIX-3] Persist explícito (sin cascade en la colección)
        $this->entityManager->persist($revaluation);
        $item->addRevaluation($revaluation);

        $item->setCurrentRealUnitCost($newRealUnitCost);
        $item->setCurrentSuggestedPrice($newSuggestedPrice);
        $item->setLastRevaluedAt(new \DateTimeImmutable());
    }

    /**
     * Cascada de margen: item.suggestedMarginPct → product.defaultMarginPct → settings.
     */
    private function resolveMarginPct(InvestmentItem $item): string
    {
        $scale = $this->settingsService->getCurrencyScale();

        $itemMargin = $item->getSuggestedMarginPct();
        if (bccomp($itemMargin, '0', $scale) > 0) {
            return $itemMargin;
        }

        $productMargin = $item->getProduct()->getDefaultMarginPct();
        if ($productMargin !== null && bccomp($productMargin, '0', $scale) > 0) {
            return $productMargin;
        }

        $settingsMargin = $this->settingsService->getDefaultMarginPct();
        if (bccomp($settingsMargin, '0', $scale) > 0) {
            return $settingsMargin;
        }

        return '40.00';
    }

    private function getCurrentStock(InvestmentItem $item): int
    {
        return $this->movementRepository->getQuantityDeltaSum($item->getId(), $item->getTenant()->getId());
    }
}
