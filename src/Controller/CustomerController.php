<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Payment;
use App\Entity\Tenant;
use App\Form\CustomerFormType;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\PaymentAllocationRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Service\ActiveTenantProvider;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD web de clientes (Fase 3a). El tenant no se recibe nunca por
 * request: sale de la sesión y la baja es `isActive = false` (los
 * clientes cuelgan de Sale y Payment, no se borran).
 */
#[Route('/clientes')]
final class CustomerController extends AbstractController
{
    private const MAX_FILTER_LENGTH = 100;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ActiveTenantProvider $activeTenantProvider,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SaleRepositoryInterface $saleRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentAllocationRepositoryInterface $allocationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('', name: 'app_customer_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $term = $this->filterTerm($request);

        $pagination = $this->paginator->paginate(
            $this->customerRepository->createPaginatedQueryBuilder($this->requireTenantId(), $term),
            $request->query->getInt('page', 1),
            10
        );

        return $this->render('customer/index.html.twig', [
            'pagination' => $pagination,
            'q' => $term,
        ]);
    }

    /**
     * Detalle del cliente (Fase 3d): datos, saldo y su historial de
     * ventas y pagos. Es la pantalla desde la que se entiende cuánto le
     * debe: el saldo es (total vendido no anulado) − (total pagado), y
     * el saldo a favor es (total pagado) − (lo que ya cubre ventas,
     * medido sobre allocations desde ADR-0018).
     */
    #[Route('/{id}', name: 'app_customer_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $tenantId = $this->requireTenantId();
        $customer = $this->findCustomer($id);

        $totalSales = $this->saleRepository->sumSalesByCustomer($customer->getId(), $tenantId);
        $totalPaid = $this->paymentRepository->sumByCustomer($customer->getId(), $tenantId);

        $payments = $this->paginator->paginate(
            $this->paymentRepository->createPaginatedQueryBuilder($tenantId, $customer->getId()),
            $request->query->getInt('page', 1),
            10
        );

        // Las ventas de cada pago se resuelven aparte (una consulta para
        // la página entera), no con un join: desde ADR-0018 el vínculo es
        // M:N y un join duplicaría cada fila.
        $pagePaymentIds = [];
        foreach ($payments->getItems() as $item) {
            if ($item instanceof Payment) {
                $pagePaymentIds[] = $item->getId();
            }
        }

        return $this->render('customer/show.html.twig', [
            'customer' => $customer,
            'sales' => $this->saleRepository->findByCustomer($customer->getId(), $tenantId),
            'payments' => $payments,
            'sale_ids' => $this->allocationRepository->saleIdsByPaymentIds($pagePaymentIds, $tenantId),
            'total_sales' => $totalSales,
            'total_paid' => $totalPaid,
            'balance' => bcsub($totalSales, $totalPaid, 2),
            'advances' => $this->paymentRepository->sumAvailableAdvancesByCustomer($customer->getId(), $tenantId),
            'methods' => Payment::METHOD_LABELS,
        ]);
    }

    #[Route('/nueva', name: 'app_customer_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $customer = new Customer();
        $customer->setTenant($this->requireTenant());

        $form = $this->createForm(CustomerFormType::class, $customer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($customer);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Cliente "%s" creado.', $customer->getName()));

            return $this->redirectToRoute('app_customer_index');
        }

        return $this->render('customer/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/editar', name: 'app_customer_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $customer = $this->findCustomer($id);

        $form = $this->createForm(CustomerFormType::class, $customer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Cliente actualizado.');

            return $this->redirectToRoute('app_customer_index');
        }

        return $this->render('customer/edit.html.twig', [
            'customer' => $customer,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/desactivar', name: 'app_customer_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(int $id, Request $request): Response
    {
        $customer = $this->findCustomer($id);

        $token = $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_customer_index');
        }

        if ($customer->isActive()) {
            $customer->setIsActive(false);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Cliente "%s" desactivado.', $customer->getName()));
        }

        return $this->redirectToRoute('app_customer_index');
    }

    private function findCustomer(int $id): Customer
    {
        $customer = $this->customerRepository->findByIdAndTenant($id, $this->requireTenantId());

        if (null === $customer) {
            throw $this->createNotFoundException('El cliente no existe.');
        }

        return $customer;
    }

    private function filterTerm(Request $request): ?string
    {
        $q = $request->query->get('q');

        if (!\is_string($q)) {
            return null;
        }

        $q = trim($q);

        return '' === $q ? null : mb_substr($q, 0, self::MAX_FILTER_LENGTH);
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
     * Tenant activo de la sesión (ADR-0015): el usuario ya no es dueño de
     * un tenant, el negocio se elige al hacer login y queda en la sesión.
     */
    private function requireTenant(): Tenant
    {
        $tenant = $this->activeTenantProvider->getTenant();

        if (null === $tenant) {
            throw $this->createAccessDeniedException('La sesión no tiene un negocio activo.');
        }

        return $tenant;
    }
}
