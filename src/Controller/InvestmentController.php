<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Entity\Investment;
use App\Exception\Domain\DomainException;
use App\Form\InvestmentCancelFormType;
use App\Form\InvestmentFormType;
use App\Form\InvestmentItemFormType;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Service\Investment\InvestmentConfirmationService;
use App\Service\Investment\InvestmentCreationService;
use App\Service\Investment\InvestmentItemService;
use App\Service\Investment\InvestmentStateMachine;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
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
        private readonly InvestmentConfirmationService $confirmationService,
        private readonly InvestmentStateMachine $stateMachine,
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
        $investment = $this->findInvestment($id);

        $itemForm = $this->createForm(InvestmentItemFormType::class, [
            'product' => null,
            'quantity' => 1,
            'unitCost' => null,
        ]);
        $cancelForm = $this->createForm(InvestmentCancelFormType::class, ['reason' => null]);
        $confirmForm = $this->createForm(FormType::class);

        return $this->render('investment/show.html.twig', [
            'investment' => $investment,
            'item_form' => $itemForm->createView(),
            'cancel_form' => $cancelForm->createView(),
            'confirm_form' => $confirmForm->createView(),
            'status_labels' => self::statusLabels(),
            'can_modify' => $investment->getStatus() === Investment::STATUS_OPEN && !$investment->isConfirmed(),
        ]);
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

        if ($investment->getStatus() !== Investment::STATUS_OPEN || $investment->isConfirmed()) {
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

    private function findInvestment(int $id): Investment
    {
        $investment = $this->investmentRepository->findByIdAndTenant($id, $this->requireTenantId());

        if (null === $investment) {
            throw $this->createNotFoundException('La inversión no existe.');
        }

        return $investment;
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
