<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Command\RegisterSaleCommand;
use App\DTO\Command\RegisterSaleLineCommand;
use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use App\Entity\Payment;
use App\Entity\Sale;
use App\Exception\Domain\DomainException;
use App\Form\SaleFormType;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\InventoryMovementRepositoryInterface;
use App\Repository\Contract\InvestmentItemRepositoryInterface;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Repository\Contract\SaleLineRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Service\Sale\SaleService;
use App\Service\Sale\SaleVoidService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ventas (Fases 3c.1 y 3c.2): consulta, alta y anulación.
 *
 * El tenant sale SIEMPRE de la sesión (ADR-0015), y el id de una venta de
 * otro negocio es indistinguible de uno inexistente: el detalle responde
 * 404 en ambos casos en vez de filtrar que el dato exista.
 *
 * Los controllers son delgados: delegan en SaleService::register y
 * SaleVoidService::void, y traducen las excepciones de dominio en flashes
 * (nunca en 500). Las reglas de negocio -inversión confirmada, stock
 * suficiente, ítem perteneciente a la inversión, ventas con pagos- no se
 * replican acá a propósito.
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
        private readonly InvestmentItemRepositoryInterface $itemRepository,
        private readonly InventoryMovementRepositoryInterface $movementRepository,
        private readonly SaleService $saleService,
        private readonly SaleVoidService $voidService,
        private readonly EntityManagerInterface $entityManager,
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

    #[Route('/nueva', name: 'app_sale_new', methods: ['GET'])]
    public function new(): Response
    {
        $tenantId = $this->requireTenantId();
        $items = $this->itemRepository->findWithRemainingStock($tenantId);

        return $this->renderNew($this->createSaleForm($tenantId, $items), $items);
    }

    #[Route('', name: 'app_sale_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $user = $this->requireAppUser();

        $items = $this->itemRepository->findWithRemainingStock($tenantId);
        $form = $this->createSaleForm($tenantId, $items);
        $form->handleRequest($request);

        // Form inválido: se re-renderiza con los errores de campo en su
        // sitio (un redirect los perdería). El stock y la pertenencia del
        // ítem NO se validan acá: llegan como excepción de dominio.
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderNew($form, $items);
        }

        /** @var array{investment: Investment, customer: ?Customer, saleDate: \DateTimeImmutable, notes: ?string, lines: list<array{investmentItem: InvestmentItem, quantity: int, unitPrice: float}>} $data */
        $data = $form->getData();

        try {
            $sale = $this->saleService->register(
                new RegisterSaleCommand(
                    investmentId: $data['investment']->getId(),
                    customerId: $data['customer']?->getId(),
                    date: $data['saleDate'],
                    lines: $this->buildLineCommands($data['lines']),
                    notes: $data['notes'],
                ),
                $user
            );
        } catch (DomainException|\InvalidArgumentException $e) {
            // Stock insuficiente, ítem de otra inversión, inversión sin
            // confirmar: el dominio lo explica y el usuario reintenta.
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_sale_new');
        }

        $this->addFlash('success', sprintf('Venta %d registrada.', $sale->getId()));

        return $this->redirectToRoute('app_sale_show', ['id' => $sale->getId()]);
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
            'method_labels' => Payment::METHOD_LABELS,
        ]);
    }

    /**
     * Anulación (devolución del cliente). CSRF plano y stateless
     * (config/packages/csrf.yaml, token id "submit"): un token inválido
     * responde 403, no un flash, para no confundir un ataque con un error
     * de datos.
     */
    #[Route('/{id}/anular', name: 'app_sale_void', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function void(int $id, Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $sale = $this->findSale($id, $tenantId);

        $this->assertValidCsrf($request);

        $reason = trim((string) $request->request->get('reason', ''));

        if ('' === $reason) {
            $this->addFlash('error', 'El motivo de la anulación es obligatorio.');

            return $this->redirectToRoute('app_sale_show', ['id' => $sale->getId()]);
        }

        if (mb_strlen($reason) > 255) {
            $this->addFlash('error', 'El motivo de la anulación no puede superar los 255 caracteres.');

            return $this->redirectToRoute('app_sale_show', ['id' => $sale->getId()]);
        }

        if ($sale->isVoided()) {
            $this->addFlash('error', 'La venta ya está anulada.');

            return $this->redirectToRoute('app_sale_show', ['id' => $sale->getId()]);
        }

        try {
            $this->voidService->void($sale, $reason, $this->requireAppUser());
            $this->entityManager->flush();

            $this->addFlash('success', 'Venta anulada.');
        } catch (DomainException $e) {
            // Con pagos aplicados no se anula (SaleHasPaymentsException):
            // es el usuario quien decide qué hacer con el cobro.
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_sale_show', ['id' => $sale->getId()]);
    }

    /**
     * @param list<array{investmentItem: InvestmentItem, quantity: int, unitPrice: float}> $lines
     *
     * @return list<RegisterSaleLineCommand>
     */
    private function buildLineCommands(array $lines): array
    {
        $commands = [];

        foreach ($lines as $line) {
            $commands[] = new RegisterSaleLineCommand(
                investmentItemId: $line['investmentItem']->getId(),
                quantity: (int) $line['quantity'],
                // El form entrega el precio como float (NumberType, scale
                // 2); el dominio trabaja con string de 2 decimales.
                unitPrice: number_format((float) $line['unitPrice'], 2, '.', '')
            );
        }

        return $commands;
    }

    /**
     * Form de venta con una fila de línea vacía: sin JavaScript la
     * colección arranca con un renglón usable.
     *
     * @param InvestmentItem[]|null $items Ítems vendibles del tenant
     */
    private function createSaleForm(int $tenantId, ?array $items = null): FormInterface
    {
        $items ??= $this->itemRepository->findWithRemainingStock($tenantId);

        return $this->createForm(SaleFormType::class, [
            'investment' => null,
            'customer' => null,
            'saleDate' => new \DateTimeImmutable('today'),
            'notes' => null,
            'lines' => [ []],
        ], [
            'investments' => $this->sellableInvestments($items),
            'customers' => $this->customerRepository->findActiveByTenant($tenantId),
            'sellable_items' => $items,
            'stock_by_item' => $this->remainingStockByItem($items, $tenantId),
        ]);
    }

    /**
     * Inversiones que se pueden vender: confirmadas y con al menos un ítem
     * con stock. SaleService vuelve a validarlo (el dominio no confía en
     * la UI), esto solo define qué se ofrece.
     *
     * @param InvestmentItem[] $items Ítems con stock remanente
     *
     * @return Investment[]
     */
    private function sellableInvestments(array $items): array
    {
        $investments = [];

        foreach ($items as $item) {
            $investment = $item->getInvestment();

            if ($investment->isConfirmed()) {
                $investments[$investment->getId()] = $investment;
            }
        }

        usort(
            $investments,
            static fn (Investment $a, Investment $b): int => strcmp($a->getCode(), $b->getCode())
        );

        return $investments;
    }

    /**
     * @param InvestmentItem[] $items
     *
     * @return array<int, int> Stock disponible por itemId
     */
    private function remainingStockByItem(array $items, int $tenantId): array
    {
        $stock = $this->movementRepository->getStockByItem($tenantId);
        $byItem = [];

        foreach ($items as $item) {
            $byItem[$item->getId()] = $stock[$item->getId()]['balance'] ?? 0;
        }

        return $byItem;
    }

    /**
     * @param InvestmentItem[] $items Ítems con stock del tenant
     */
    private function renderNew(FormInterface $form, array $items): Response
    {
        return $this->render('sale/new.html.twig', [
            'form' => $form->createView(),
            'sellable_items' => $items,
        ]);
    }

    private function findSale(int $id, int $tenantId): Sale
    {
        $sale = $this->saleRepository->findByIdAndTenant($id, $tenantId);

        if (null === $sale) {
            throw $this->createNotFoundException('La venta no existe.');
        }

        return $sale;
    }

    /**
     * CSRF plano con el token id "submit" (stateless, ver
     * config/packages/csrf.yaml): el campo _csrf_token del POST, sin form
     * de Symfony. Un token inválido responde 403, no un flash, para no
     * confundir un ataque con un error de datos.
     */
    private function assertValidCsrf(Request $request): void
    {
        $token = $request->request->all()['_csrf_token'] ?? null;

        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            throw $this->createAccessDeniedException('CSRF inválido.');
        }
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

    private function requireAppUser(): AppUser
    {
        $user = $this->getUser();

        if (!$user instanceof AppUser) {
            throw $this->createAccessDeniedException('Sesión inválida.');
        }

        return $user;
    }
}
