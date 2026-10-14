<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\InvestmentExpense;
use App\Exception\Domain\DomainException;
use App\Form\InvestmentCancelFormType;
use App\Form\InvestmentExpenseFormType;
use App\Form\InvestmentFormType;
use App\Form\InvestmentItemFormType;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentCreationService;
use App\Service\Investment\InvestmentExpenseService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Investment\InvestmentRevaluationService;
use App\Service\Investment\InvestmentStateMachine;
use App\Service\TenantContext;
use App\Service\UsdRateService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD web de inversiones (Fase 2). El controller orquesta forms y
 * delega en los servicios del dominio (ADR-0010); las excepciones de
 * reglas de negocio se traducen en flashes, nunca en 500.
 */
#[Route('/inversiones')]
final class InvestmentController extends AbstractController
{
    private const VALID_STATUSES = [
        Investment::STATUS_OPEN,
        Investment::STATUS_PARTIAL,
        Investment::STATUS_RECOVERED,
        Investment::STATUS_CLOSED,
        Investment::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly InvestmentCreationService $creationService,
        private readonly InvestmentItemService $itemService,
        private readonly InvestmentExpenseService $expenseService,
        private readonly InvestmentConfirmationService $confirmationService,
        private readonly InvestmentRevaluationService $revaluationService,
        private readonly InvestmentStateMachine $stateMachine,
        private readonly UsdRateService $usdRateService,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('', name: 'app_investment_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId = $this->requireTenantId();

        $status = $request->query->get('status');
        if (!\is_string($status) || !\in_array($status, self::VALID_STATUSES, true)) {
            $status = null;
        }

        $pagination = $this->paginator->paginate(
            $this->investmentRepository->createPaginatedQueryBuilder($tenantId, $status),
            $request->query->getInt('page', 1),
            10
        );

        return $this->render('investment/index.html.twig', [
            'pagination' => $pagination,
            'current_status' => $status,
            'status_labels' => self::statusLabels(),
        ]);
    }

    #[Route('/nueva', name: 'app_investment_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireTenantId();
        $user = $this->requireAppUser();

        $form = $this->createForm(InvestmentFormType::class, [
            'investmentDate' => new \DateTimeImmutable('today'),
            'description' => null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{investmentDate: \DateTimeImmutable, description: ?string} $data */
            $data = $form->getData();
            $investment = $this->creationService->createDraft(
                $user,
                $data['investmentDate'],
                $data['description'] ?? null
            );

            $this->entityManager->persist($investment);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Inversión %s creada.', $investment->getCode()));

            return $this->redirectToRoute('app_investment_show', ['id' => $investment->getId()]);
        }

        return $this->render('investment/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_investment_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        return $this->renderShow($this->findInvestment($id), $this->createExpenseForm());
    }

    #[Route('/{id}/gastos', name: 'app_investment_add_expense', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addExpense(int $id, Request $request): Response
    {
        $investment = $this->findInvestment($id);
        $this->assertValidCsrf($request, 'investment_expense_form');

        if (!$this->canModify($investment)) {
            $this->addFlash('error', 'Solo las inversiones abiertas sin confirmar pueden recibir gastos.');

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        $form = $this->createExpenseForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{category: string, amount: string, expenseDate: \DateTimeImmutable, description: ?string, isAllocated: bool} $data */
            $data = $form->getData();

            try {
                $this->expenseService->addExpense(
                    $investment,
                    $data['category'],
                    number_format((float) $data['amount'], 2, '.', ''),
                    $data['expenseDate'],
                    $data['description'] ?? null,
                    (bool) $data['isAllocated'],
                );
                $this->entityManager->flush();

                $this->addFlash('success', 'Gasto agregado.');
            } catch (DomainException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        // Form inválido (validación de campos): se re-renderiza el detalle
        // con los errores en su sitio; un redirect los perdería.
        return $this->renderShow($investment, $form);
    }

    #[Route('/{id}/gastos/{expenseId}/eliminar', name: 'app_investment_remove_expense', requirements: ['id' => '\d+', 'expenseId' => '\d+'], methods: ['POST'])]
    public function removeExpense(int $id, int $expenseId, Request $request): Response
    {
        $investment = $this->findInvestment($id);
        $this->assertValidCsrf($request, null);

        if (!$this->canModify($investment)) {
            $this->addFlash('error', 'Solo las inversiones abiertas sin confirmar pueden modificar sus gastos.');

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        $expense = null;
        foreach ($investment->getExpenses() as $candidate) {
            if ($candidate->getId() === $expenseId) {
                $expense = $candidate;
                break;
            }
        }

        if (!$expense instanceof InvestmentExpense) {
            throw $this->createNotFoundException('El gasto no existe.');
        }

        try {
            $this->expenseService->removeExpense($expense);
            $this->entityManager->flush();

            $this->addFlash('success', 'Gasto eliminado.');
        } catch (DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_investment_show', ['id' => $id]);
    }

    #[Route('/{id}/items', name: 'app_investment_add_item', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addItem(int $id, Request $request): Response
    {
        $investment = $this->findInvestment($id);

        $itemForm = $this->createForm(InvestmentItemFormType::class, [
            'product' => null,
            'quantity' => 1,
            'unitCost' => null,
        ]);
        $itemForm->handleRequest($request);

        if ($itemForm->isSubmitted() && $itemForm->isValid()) {
            /** @var array{product: \App\Entity\Product, quantity: int, unitCost: float} $data */
            $data = $itemForm->getData();

            try {
                $this->itemService->addItem(
                    $investment,
                    $data['product'],
                    (int) $data['quantity'],
                    number_format((float) $data['unitCost'], 2, '.', '')
                );
                $this->entityManager->flush();

                $this->addFlash('success', 'Ítem agregado.');
            } catch (DomainException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        $this->addFlash('error', 'Datos del ítem inválidos.');

        return $this->redirectToRoute('app_investment_show', ['id' => $id]);
    }

    #[Route('/{id}/editar', name: 'app_investment_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $investment = $this->findInvestment($id);

        if (!$this->canModify($investment)) {
            $this->addFlash('error', 'Solo las inversiones abiertas sin confirmar pueden editarse.');

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        $form = $this->createForm(InvestmentFormType::class, [
            'investmentDate' => $investment->getInvestmentDate(),
            'description' => $investment->getDescription(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{investmentDate: \DateTimeImmutable, description: ?string} $data */
            $data = $form->getData();
            $investment->setInvestmentDate($data['investmentDate']);
            $investment->setDescription($data['description'] ?? null);

            $this->entityManager->flush();

            $this->addFlash('success', 'Inversión actualizada.');

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        return $this->render('investment/edit.html.twig', [
            'investment' => $investment,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/confirmar', name: 'app_investment_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(int $id, Request $request): Response
    {
        $investment = $this->findInvestment($id);

        $form = $this->createForm(FormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        try {
            $this->confirmationService->confirm($investment);
            $this->entityManager->flush();

            $this->addFlash('success', 'Inversión confirmada.');
        } catch (DomainException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_investment_show', ['id' => $id]);
    }

    #[Route('/{id}/cancelar', name: 'app_investment_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $investment = $this->findInvestment($id);

        $form = $this->createForm(InvestmentCancelFormType::class, ['reason' => null]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'El motivo de cancelación es obligatorio.');

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        try {
            /** @var array{reason: string} $data */
            $data = $form->getData();
            $this->stateMachine->cancel($investment, $data['reason']);
            $this->entityManager->flush();

            $this->addFlash('success', 'Inversión cancelada.');
        } catch (DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_investment_show', ['id' => $id]);
    }

    #[Route('/{id}/cerrar', name: 'app_investment_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(int $id, Request $request): Response
    {
        $investment = $this->findInvestment($id);

        // CSRF plano con token ID "form" (coincide con el formulario renderizado en la plantilla)
        $this->assertValidCsrf($request, 'form');

        try {
            $this->stateMachine->close($investment);
            $this->entityManager->flush();

            $this->addFlash('success', 'Inversión cerrada.');
        } catch (DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_investment_show', ['id' => $id]);
    }

    #[Route('/{id}/revaluar', name: 'app_investment_revalue', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revalue(int $id, Request $request): Response
    {
        $investment = $this->findInvestment($id);
        $this->assertValidCsrf($request, 'form');

        $user = $this->requireAppUser();

        // Obtener la tasa vigente
        $currentRate = $this->usdRateService->getCurrent();
        if ($currentRate === null) {
            $this->addFlash('error', 'No hay tasa USD vigente registrada. Registre una tasa antes de revaluar.');

            return $this->redirectToRoute('app_investment_show', ['id' => $id]);
        }

        try {
            $oldRate = $investment->getCurrentUsdRate();
            $this->revaluationService->revalue($investment, $currentRate, $user);
            $this->entityManager->flush();

            $newRate = $investment->getCurrentUsdRate();
            $impact = $this->calculateRevaluationImpact($investment);

            $this->addFlash('success', sprintf('Inversión revaluada. Impacto: %s%s', $impact >= 0 ? '+' : '', $impact));
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_investment_show', ['id' => $id]);
    }

    /**
     * Calcula el impacto total de la revaluación (diferencia entre totalInvestmentCurrent y totalInvestment).
     */
    private function calculateRevaluationImpact(\App\Entity\Investment $investment): string
    {
        $summary = $investment->getSummary();
        if ($summary === null) {
            return '0.00';
        }

        $current = $summary->getTotalInvestmentCurrent();
        $historical = $summary->getTotalInvestment();

        return bcsub($current, $historical, 2);
    }

    private function findInvestment(int $id): Investment
    {
        $investment = $this->investmentRepository->findByIdAndTenant($id, $this->requireTenantId());

        if (null === $investment) {
            throw $this->createNotFoundException('La inversión no existe.');
        }

        return $investment;
    }

    /**
     * Detalle de la inversión (GET) o re-render del detalle cuando el form
     * de gastos vuelve inválido: el mismo FormInterface permite conservar
     * lo enviado y mostrar los errores de campo en su sitio.
     */
    private function renderShow(Investment $investment, FormInterface $expenseForm): Response
    {
        $itemForm = $this->createForm(InvestmentItemFormType::class, [
            'product' => null,
            'quantity' => 1,
            'unitCost' => null,
        ]);
        $cancelForm = $this->createForm(InvestmentCancelFormType::class, ['reason' => null]);
        $confirmForm = $this->createForm(FormType::class);
        $closeForm = $this->createForm(FormType::class);
        $revalueForm = $this->createForm(FormType::class);

        // expense_form como FormInterface (no createView()): render()
        // fija 422 solo cuando el form de gastos vuelve inválido desde
        // addExpense, que es lo que Turbo Drive exige para re-renderizar
        // en vez de exigir una redirección. En el GET de show() el form
        // no está submitted y el status queda 200.
        return $this->render('investment/show.html.twig', [
            'investment' => $investment,
            'item_form' => $itemForm->createView(),
            'cancel_form' => $cancelForm->createView(),
            'confirm_form' => $confirmForm->createView(),
            'close_form' => $closeForm->createView(),
            'revalue_form' => $revalueForm->createView(),
            'expense_form' => $expenseForm,
            'status_labels' => self::statusLabels(),
            'expense_category_labels' => InvestmentExpense::CATEGORY_LABELS,
            'can_modify' => $this->canModify($investment),
            'can_close' => $this->canClose($investment),
        ]);
    }

    /**
     * Form de gasto vacío con los defaults de la UI (fecha de hoy y
     * gasto prorrateable, que es el caso habitual).
     */
    private function createExpenseForm(): FormInterface
    {
        return $this->createForm(InvestmentExpenseFormType::class, [
            'category' => null,
            'amount' => null,
            'expenseDate' => new \DateTimeImmutable('today'),
            'description' => null,
            'isAllocated' => true,
        ]);
    }

    /**
     * Solo una inversión abierta y no confirmada admite cambios (ítems,
     * gastos, edición de cabecera).
     */
    private function canModify(Investment $investment): bool
    {
        return Investment::STATUS_OPEN === $investment->getStatus() && !$investment->isConfirmed();
    }

    /**
     * Una inversión se puede cerrar desde OPEN, PARTIAL o RECOVERED.
     * No se puede cerrar desde CLOSED (idempotente) ni CANCELLED (error).
     */
    private function canClose(Investment $investment): bool
    {
        $status = $investment->getStatus();

        return \in_array($status, [
            Investment::STATUS_OPEN,
            Investment::STATUS_PARTIAL,
            Investment::STATUS_RECOVERED,
        ], true);
    }

    /**
     * CSRF plano con el token id "submit" (stateless, ver
     * config/packages/csrf.yaml): sirve igual para los forms embebidos y
     * para los POST sueltos. Un token inválido responde 403, no un
     * redirect con flash, para no confundir un ataque con un error de datos.
     *
     * @param string|null $formName Nombre del form (su campo _token) o null
     *                              para un POST con campo _csrf_token plano
     */
    private function assertValidCsrf(Request $request, ?string $formName): void
    {
        $payload = null !== $formName ? $request->request->all($formName) : $request->request->all();
        $token = null !== $formName ? ($payload['_token'] ?? null) : ($payload['_csrf_token'] ?? null);

        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            throw $this->createAccessDeniedException('CSRF inválido.');
        }
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

    /**
     * @return array<string, string>
     */
    private static function statusLabels(): array
    {
        return [
            Investment::STATUS_OPEN => 'Abierta',
            Investment::STATUS_PARTIAL => 'Parcial',
            Investment::STATUS_RECOVERED => 'Recuperada',
            Investment::STATUS_CLOSED => 'Cerrada',
            Investment::STATUS_CANCELLED => 'Cancelada',
        ];
    }
}
