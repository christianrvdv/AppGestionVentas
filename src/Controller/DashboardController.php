<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\InvestmentSummaryRepositoryInterface;
use App\Service\TenantContext;
use App\Service\UsdRateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard mínimo (Fase 1): totales consolidados, tasa USD vigente
 * e inversiones activas del tenant autenticado.
 *
 * El tenant activo lo puebla TenantFilterListener desde la clave de
 * sesión active_tenant_id (ADR-0015); este controller no resuelve
 * tenants por su cuenta.
 *
 * EXCEPCIÓN super_admin (ADR-0016): el rol global no opera con un
 * negocio concreto, así que accede al dashboard sin tenant activo con
 * los totales en cero (no hay un negocio del cual consolidar). Cuando
 * el panel /admin exista (fase posterior), será su destino natural.
 */
#[Route('/')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InvestmentSummaryRepositoryInterface $summaryRepository,
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly UsdRateService $usdRateService,
    ) {
    }

    #[Route('', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($tenantId === null) {
            $user = $this->getUser();

            if (!$user instanceof AppUser || !$user->isSuperAdmin()) {
                throw $this->createAccessDeniedException('La sesión no tiene un negocio activo.');
            }

            return $this->render('dashboard/index.html.twig', [
                'totals' => self::emptyTotals(),
                'usd_rate' => null,
                'active_investments' => [],
            ]);
        }

        return $this->render('dashboard/index.html.twig', [
            'totals' => $this->summaryRepository->sumTotalsByTenant($tenantId),
            'usd_rate' => $this->usdRateService->getCurrent(),
            'active_investments' => $this->investmentRepository->findActiveByTenant($tenantId),
        ]);
    }

    /**
     * Totales en cero con el mismo shape que
     * InvestmentSummaryRepositoryInterface::sumTotalsByTenant(), para
     * que el template del dashboard rinda igual con y sin tenant.
     *
     * @return array<string, string>
     */
    private static function emptyTotals(): array
    {
        return array_fill_keys([
            'totalInvestment',
            'totalInvestmentCurrent',
            'totalRecovered',
            'totalRecoveredPerProduct',
            'totalRecoveredInvestmentFirst',
            'totalRecoveredCurrent',
            'totalPending',
            'totalPendingCurrent',
            'totalGrossProfit',
            'totalRecognizedProfit',
            'totalProfit',
            'totalProfitPerProduct',
            'totalProfitInvestmentFirst',
        ], '0.00');
    }
}

