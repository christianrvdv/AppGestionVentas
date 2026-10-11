<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Payment;
use App\Entity\Sale;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Repository\Contract\SaleLineRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Service\TenantContext;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consulta de ventas (Fase 3c.1): solo lectura.
 *
 * Registrar y anular ventas es Fase 3c.2 (formularios), así que este
 * controlador no escribe nada: ni EntityManager ni CSRF. El tenant sale
 * SIEMPRE de la sesión (ADR-0015), y el id de una venta de otro negocio
 * es indistinguible de uno inexistente: el detalle responde 404 en ambos
 * casos en vez de filtrar que el dato exista.
 */
#[Route('/ventas')]
final class SaleController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SaleRepositoryInterface $saleRepository,
        private readonly SaleLineRepositoryInterface $saleLineRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('', name: 'app_sale_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $investmentId = $this->filterId($request->query->get('investment'));
        $customerId = $this->filterId($request->query->get('customer'));
        $from = $this->filterDate($request->query->get('from'));
        $to = $this->filterDate($request->query->get('to'));
        $includeVoided = $request->query->getBoolean('include_voided');

        $pagination = $this->paginator->paginate(
            $this->saleRepository->createPaginatedQueryBuilder($tenantId, $investmentId, $customerId, $from, $to, $includeVoided),
            $request->query->getInt('page', 1),
            10
        );

        // La ganancia bruta de cada fila se resuelve aparte (una consulta
        // por página) porque ponerla en el DQL paginado obligaría a un
        // GROUP BY, y ese GROUP BY rompe el COUNT del paginador.
        $pageSaleIds = [];
        foreach ($pagination->getItems() as $item) {
            if ($item instanceof Sale) {
                $pageSaleIds[] = $item->getId();
            }
        }

        return $this->render('sale/index.html.twig', [
            'pagination' => $pagination,
            'gross_profit' => $this->saleRepository->sumGrossProfitBySaleIds($pageSaleIds, $tenantId),
            'investments' => $this->investmentRepository->findAllByTenant($tenantId),
            'customers' => $this->customerRepository->findActiveByTenant($tenantId),
            'filters' => [
                'investment' => $investmentId,
                'customer' => $customerId,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
                'include_voided' => $includeVoided,
            ],
        ]);
    }

    #[Route('/{id}', name: 'app_sale_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $tenantId = $this->requireTenantId();

        $sale = $this->saleRepository->findByIdAndTenant($id, $tenantId);

        if (null === $sale) {
            throw $this->createNotFoundException('La venta no existe.');
        }

        return $this->render('sale/show.html.twig', [
            'sale' => $sale,
            'lines' => $this->saleLineRepository->findBySale($sale->getId(), $tenantId),
            'payments' => $this->paymentRepository->findBySale($sale->getId(), $tenantId),
            'method_labels' => self::methodLabels(),
        ]);
    }

    /**
     * Id de filtro recibido por query string. Un valor ausente, no
     * numérico o 0 significa "sin filtro": el listado no debe fallar
     * porque alguien editó la URL a mano.
     */
    private function filterId(mixed $value): ?int
    {
        if (!\is_string($value) || !preg_match('/^\d+$/', $value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * Fecha de filtro en formato Y-m-d (el de <input type="date">).
     * Cualquier otra cosa se ignora en silencio.
     */
    private function filterDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();

        if (false === $date || (\is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    private function requireTenantId(): int
    {
        $tenantId = $this->tenantContext->getTenantId();

        if (null === $tenantId) {
            throw $this->createAccessDeniedException('La sesión no tiene un negocio activo.');
        }

        return $tenantId;
    }

    /**
     * @return array<string, string>
     */
    private static function methodLabels(): array
    {
        return [
            Payment::METHOD_CASH => 'Efectivo',
            Payment::METHOD_TRANSFER => 'Transferencia',
            Payment::METHOD_CARD => 'Tarjeta',
            Payment::METHOD_OTHER => 'Otro',
        ];
    }
}
