<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\Tenant;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Service\SettingsService;
use App\Service\UsdRateService;

/**
 * Crea inversiones en borrador (estado OPEN).
 *
 * Responsabilidades:
 *   - Genera código único por tenant: INV-YYYYMMDD-NNN (secuencial por día).
 *   - Setea moneda base, tasa USD snapshot, modo de recuperación por defecto.
 *   - Estado inicial OPEN, confirmedAt = null.
 *   - NO agrega ítems ni gastos (eso lo hacen InvestmentItemService/InvestmentExpenseService).
 *
 * El llamador debe persistir y flush.
 */
final class InvestmentCreationService
{
    public function __construct(
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly SettingsService $settingsService,
        private readonly UsdRateService $usdRateService
    ) {}

    /**
     * Crea una inversión en borrador.
     *
     * @param AppUser              $user         Usuario que crea la inversión
     * @param \DateTimeImmutable   $date         Fecha de la inversión
     * @param string|null          $description  Descripción opcional
     *
     * @return Investment Entidad lista para persistir (estado OPEN)
     */
    public function createDraft(
        AppUser $user,
        \DateTimeImmutable $date,
        ?string $description = null
    ): Investment {
        $tenant = $user->getTenant();
        $tenantId = $tenant->getId();

        // Genera código único: INV-YYYYMMDD-NNN
        $datePrefix = $date->format('Ymd');
        $codePrefix = "INV-{$datePrefix}-";
        $sequence = $this->investmentRepository->countByCodePrefix($tenantId, $codePrefix) + 1;
        $code = $codePrefix . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);

        // Tasa USD actual (puede ser null si no hay tasa registrada)
        $currentUsdRate = $this->usdRateService->getCurrent();
        $usdRateSnapshot = $currentUsdRate?->getRate();

        $investment = new Investment();
        $investment->setTenant($tenant);
        $investment->setCreatedBy($user);
        $investment->setCode($code);
        $investment->setInvestmentDate($date);
        $investment->setDescription($description);
        $investment->setBaseCurrency($this->settingsService->getBaseCurrency());
        $investment->setUsdRateSnapshot($usdRateSnapshot);
        $investment->setCurrentUsdRate($usdRateSnapshot);
        $investment->setRecoveryMode($this->settingsService->getRecoveryModeDefault());
        $investment->setStatus(Investment::STATUS_OPEN);
        $investment->setAllocationMethod(Investment::ALLOCATION_METHOD_VALUE);
        // Totales se calculan al confirmar (InvestmentConfirmationService)

        return $investment;
    }
}