<?php

declare(strict_types=1);

namespace App\Controller;

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
            throw $this->createAccessDeniedException('La sesión no tiene un negocio activo.');
        }

        return $this->render('dashboard/index.html.twig', [
            'totals' => $this->summaryRepository->sumTotalsByTenant($tenantId),
            'usd_rate' => $this->usdRateService->getCurrent(),
            'active_investments' => $this->investmentRepository->findActiveByTenant($tenantId),
        ]);
    }
}
