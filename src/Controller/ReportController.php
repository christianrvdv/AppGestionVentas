<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Report\InvestmentReportFilters;
use App\DTO\Report\ProductRankingFilters;
use App\DTO\Report\ProfitReportFilters;
use App\Service\Report\InvestmentReportService;
use App\Service\Report\ProductRankingService;
use App\Service\Report\ProfitReportService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reportes básicos (Fase 4.1): ganancias, inversiones, productos.
 *
 * Cada action delega en su service correspondiente y renderiza
 * el template asociado. Los filtros se construyen desde el Request.
 */
#[Route('/reportes')]
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ProfitReportService $profitReportService,
        private readonly InvestmentReportService $investmentReportService,
        private readonly ProductRankingService $productRankingService,
    ) {
    }

    #[Route('/ganancias', name: 'app_report_profit', methods: ['GET'])]
    public function profit(Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $filters = $this->buildProfitFilters($request);
        $data = $this->profitReportService->generate($tenantId, $filters);

        return $this->render('report/profit.html.twig', $data);
    }

    #[Route('/inversiones', name: 'app_report_investment', methods: ['GET'])]
    public function investment(Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $filters = $this->buildInvestmentFilters($request);
        $data = $this->investmentReportService->generate($tenantId, $filters);

        return $this->render('report/investment.html.twig', $data);
    }

    #[Route('/productos', name: 'app_report_product', methods: ['GET'])]
    public function product(Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $filters = $this->buildProductFilters($request);
        $data = $this->productRankingService->generate($tenantId, $filters);

        return $this->render('report/product.html.twig', $data);
    }

    private function buildProfitFilters(Request $request): ProfitReportFilters
    {
        $from = $this->parseDate($request->query->get('from'), '-30 days');
        $to = $this->parseDate($request->query->get('to'), 'today');
        $granularity = $request->query->get('granularity', 'day');
        $investmentId = $request->query->getInt('investment');
        $customerId = $request->query->getInt('customer');

        return new ProfitReportFilters(
            from: $from,
            to: $to,
            granularity: $granularity,
            investmentId: $investmentId > 0 ? $investmentId : null,
            customerId: $customerId > 0 ? $customerId : null,
        );
    }

    private function buildInvestmentFilters(Request $request): InvestmentReportFilters
    {
        $status = $request->query->get('status');
        $from = $request->query->get('from') ? new \DateTimeImmutable($request->query->get('from')) : null;
        $to = $request->query->get('to') ? new \DateTimeImmutable($request->query->get('to')) : null;
        $sortBy = $request->query->get('sortBy', 'recoveryPct');
        $sortDir = $request->query->get('sortDir', 'DESC');

        return new InvestmentReportFilters(
            status: $status !== '' ? $status : null,
            from: $from,
            to: $to,
            sortBy: $sortBy,
            sortDir: $sortDir,
        );
    }

    private function buildProductFilters(Request $request): ProductRankingFilters
    {
        $from = $this->parseDate($request->query->get('from'), '-30 days');
        $to = $this->parseDate($request->query->get('to'), 'today');
        $limit = $request->query->getInt('limit', 10);

        return new ProductRankingFilters(
            from: $from,
            to: $to,
            limit: $limit,
        );
    }

    private function parseDate(?string $value, string $default): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable($default);
        }
        return new \DateTimeImmutable($value);
    }

    private function requireTenantId(): int
    {
        $tenantId = $this->tenantContext->getTenantId();

        if (null === $tenantId) {
            throw $this->createAccessDeniedException('La sesión no tiene un negocio activo.');
        }

        return $tenantId;
    }
}