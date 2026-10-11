<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\Command\PaymentCommand;
use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\Payment;
use App\Entity\Sale;
use App\Exception\Domain\DomainException;
use App\Form\PaymentFormType;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Service\Payment\PaymentService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pagos (Fase 3d): listado, alta de anticipo y alta sobre una venta.
 *
 * El tenant sale SIEMPRE de la sesión (ADR-0015), y el id de una venta,
 * cliente o inversión de otro negocio es indistinguible de uno
 * inexistente: responde 404 en ambos casos.
 *
 * Los controllers son delgados: delegan en PaymentService::register y
 * traducen las excepciones de dominio en flashes (nunca en 500). Moneda,
 * coincidencia de cliente y pertenencia al tenant NO se replican acá.
 *
 * Dos formas de registrar, elegidas por la ruta:
 *   - POST /ventas/{id}/pago: el pago se aplica a esa venta. Inversión
 *     (moneda base) y cliente salen de la venta: no son campos editables,
 *     así el cobro no puede quedar desalineado de lo cobrado.
 *   - POST /pagos: pago a cuenta / anticipo. Pide inversión y cliente.
 *
 * El pago PARCIAL explícito, los reembolsos y la conciliación están
 * fuera de alcance de esta fase: el usuario ve el pendiente y decide el
 * monto; el dominio no limita el total registrado.
 */
final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PaymentService $paymentService,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly SaleRepositoryInterface $saleRepository,
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('/pagos', name: 'app_payment_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $customerId = $this->filterId($request->query->get('customer'));
        $saleId = $this->filterId($request->query->get('sale'));
        $from = $this->filterDate($request->query->get('from'));
        $to = $this->filterDate($request->query->get('to'));
        $method = $this->filterMethod($request->query->get('method'));

        $pagination = $this->paginator->paginate(
            $this->paymentRepository->createPaginatedQueryBuilder($tenantId, $customerId, $saleId, $from, $to, $method),
            $request->query->getInt('page', 1),
            10
        );

        return $this->render('payment/index.html.twig', [
            'pagination' => $pagination,
            'customers' => $this->customerRepository->findActiveByTenant($tenantId),
            'methods' => Payment::METHOD_LABELS,
            'filters' => [
                'customer' => $customerId,
                'sale' => $saleId,
                'from' => $from?->format('Y-m-d'),
                'to' => $to?->format('Y-m-d'),
                'method' => $method,
            ],
        ]);
    }

    #[Route('/pagos/nuevo', name: 'app_payment_new', methods: ['GET'])]
    public function new(): Response
    {
        $tenantId = $this->requireTenantId();

        return $this->renderNew($this->createPaymentForm(null, $tenantId), null, $tenantId);
    }

    #[Route('/pagos', name: 'app_payment_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $tenantId = $this->requireTenantId();

        return $this->store($request, null, $tenantId);
    }

    #[Route('/ventas/{id}/pago', name: 'app_payment_new_for_sale', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function newForSale(int $id): Response
    {
        $tenantId = $this->requireTenantId();
        $sale = $this->findSale($id, $tenantId);

        return $this->renderNew($this->createPaymentForm($sale, $tenantId), $sale, $tenantId);
    }

    #[Route('/ventas/{id}/pago', name: 'app_payment_store_for_sale', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function storeForSale(int $id, Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $sale = $this->findSale($id, $tenantId);

        return $this->store($request, $sale, $tenantId);
    }

    /**
     * Flujo de pago compartido por las dos rutas POST.
     */
    private function store(Request $request, ?Sale $sale, int $tenantId): Response
    {
        $user = $this->requireAppUser();

        // Una venta anulada no existe a efectos del negocio: no se le
        // registran cobros (el detalle ni siquiera ofrece el botón, pero
        // la URL se puede escribir a mano).
        if ($sale instanceof Sale && $sale->isVoided()) {
            $this->addFlash('error', 'No se pueden registrar pagos sobre una venta anulada.');

            return $this->redirectToRoute('app_sale_show', ['id' => $sale->getId()]);
        }

        $form = $this->createPaymentForm($sale, $tenantId);

        // CSRF explícito A pesar de que el form también valida su token:
        // si lo dejamos solo al form, un token caducado re-renderiza la
        // página sin ningún mensaje (el campo _token no se pinta y sus
        // errores no llegan al HTML). 403, igual que los POST planos.
        $this->assertValidCsrf($request, $form);

        $form->handleRequest($request);

        // Form inválido: se re-renderiza con los errores de campo en su
        // sitio (un redirect los perdería). La pertenencia al tenant, la
        // moneda y el cliente coincidente con el de la venta NO se
        // validan acá: llegan como excepción de dominio.
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderNew($form, $sale, $tenantId);
        }

        /** @var array{investment: ?Investment, customer: ?Customer, amount: float, date: \DateTimeImmutable, method: string, reference: ?string, notes: ?string} $data */
        $data = $form->getData();

        // En el flujo de una venta, inversión y cliente no son campos del
        // form: los toma la venta (PaymentService vuelve a validarlos).
        $investment = $sale?->getInvestment() ?? $data['investment'];
        $customer = $sale?->getCustomer() ?? $data['customer'];

        // El form entrega el monto como float (NumberType, scale 2); el
        // dominio trabaja con string de 2 decimales.
        $amount = number_format((float) $data['amount'], 2, '.', '');

        try {
            $payment = $this->paymentService->register(
                new PaymentCommand(
                    investmentId: $investment->getId(),
                    customerId: $customer?->getId(),
                    saleId: $sale?->getId(),
                    amount: $amount,
                    date: $data['date'],
                    method: $data['method'],
                    reference: $data['reference'],
                    notes: $data['notes'],
                ),
                $user
            );
        } catch (DomainException|\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectAfterError($sale);
        }

        // PaymentService no recalcula el InvestmentSummary (los pagos no
        // mueven la recuperación de la inversión, solo el dinero cobrado),
        // así que el único flush es este.
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Pago de %s registrado.', number_format((float) $amount, 2, ',', '.')));

        return $sale instanceof Sale
            ? $this->redirectToRoute('app_sale_show', ['id' => $sale->getId()])
            : $this->redirectToRoute('app_payment_index');
    }

    /**
     * Form de pago. Cuando $sale está seteada no se piden inversión ni
     * cliente: los toma la venta y el form solo recibe monto, fecha,
     * método, referencia y notas.
     */
    private function createPaymentForm(?Sale $sale, int $tenantId): FormInterface
    {
        $withLookups = null === $sale;

        return $this->createForm(PaymentFormType::class, [
            'investment' => null,
            'customer' => null,
            'amount' => null,
            'date' => new \DateTimeImmutable('today'),
            'method' => Payment::METHOD_CASH,
            'reference' => null,
            'notes' => null,
        ], [
            'sale' => $sale,
            'investments' => $withLookups ? $this->investmentRepository->findAllByTenant($tenantId) : [],
            'customers' => $withLookups ? $this->customerRepository->findActiveByTenant($tenantId) : [],
        ]);
    }

    private function renderNew(FormInterface $form, ?Sale $sale, int $tenantId): Response
    {
        $totalPaid = null;
        $pending = null;

        if ($sale instanceof Sale) {
            $totalPaid = $this->paymentRepository->sumBySale($sale->getId(), $tenantId);
            $pending = bcsub((string) $sale->getTotalAmount(), $totalPaid, 2);
        }

        return $this->render('payment/new.html.twig', [
            'form' => $form->createView(),
            'sale' => $sale,
            'total_paid' => $totalPaid,
            'pending' => $pending,
        ]);
    }

    private function redirectAfterError(?Sale $sale): Response
    {
        return $sale instanceof Sale
            ? $this->redirectToRoute('app_payment_new_for_sale', ['id' => $sale->getId()])
            : $this->redirectToRoute('app_payment_new');
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
     * CSRF con el token id "submit" (stateless, ver
     * config/packages/csrf.yaml), leído del campo _token que el form de
     * Symfony renderiza. Un token inválido responde 403, no un flash, para
     * no confundir un ataque con un error de datos: la misma postura que
     * los controllers de POST plano.
     */
    private function assertValidCsrf(Request $request, FormInterface $form): void
    {
        $token = $request->request->all($form->getName())['_token'] ?? null;

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

    /**
     * Método de pago del filtro. Solo se acepta uno de los del dominio:
     * cualquier otra cosa se ignora en silencio (el listado no filtra).
     */
    private function filterMethod(mixed $value): ?string
    {
        if (!\is_string($value) || !\in_array($value, array_keys(Payment::METHOD_LABELS), true)) {
            return null;
        }

        return $value;
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
