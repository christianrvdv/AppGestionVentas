<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Entity\UsdRate;
use App\Form\UsdRateFormType;
use App\Repository\Contract\UsdRateRepositoryInterface;
use App\Service\UsdRateService;
use App\Service\TenantContext;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tasas-usd')]
final class UsdRateController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UsdRateRepositoryInterface $rateRepository,
        private readonly UsdRateService $usdRateService,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('', name: 'app_usd_rate_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenantId = $this->requireTenantId();

        $from = $request->query->get('from');
        $to = $request->query->get('to');

        $qb = $this->rateRepository->createPaginatedQueryBuilder($tenantId);

        if ($from !== null && $from !== '') {
            $fromDate = new \DateTimeImmutable($from);
            $qb->andWhere('r.rateDate >= :from')
               ->setParameter('from', $fromDate);
        }

        if ($to !== null && $to !== '') {
            $toDate = new \DateTimeImmutable($to);
            $qb->andWhere('r.rateDate <= :to')
               ->setParameter('to', $toDate);
        }

        $pagination = $this->paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            20
        );

        $currentRate = $this->usdRateService->getCurrent();
        $currentRateId = $currentRate?->getId();

        return $this->render('usd_rate/index.html.twig', [
            'pagination' => $pagination,
            'current_rate_id' => $currentRateId,
            'filter_from' => $from,
            'filter_to' => $to,
        ]);
    }

    #[Route('/nueva', name: 'app_usd_rate_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireTenantId();
        $user = $this->requireAppUser();

        $form = $this->createForm(UsdRateFormType::class, [
            'rateDate' => new \DateTimeImmutable('today'),
            'rate' => null,
            'note' => null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{rateDate: \DateTimeImmutable, rate: string, note: ?string} $data */
            $data = $form->getData();

            try {
                $rate = $this->usdRateService->setRate(
                    $data['rateDate'],
                    $data['rate'],
                    $user,
                    $data['note'] ?? null
                );

                if ($rate->isCorrection()) {
                    $this->addFlash('success', 'Tasa registrada como corrección de la existente.');
                } else {
                    $this->addFlash('success', 'Tasa USD registrada correctamente.');
                }

                return $this->redirectToRoute('app_usd_rate_index');
            } catch (\LogicException|\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        // FormInterface en vez de createView(): render() fija 422 solo
        // cuando el form es inválido, que es lo que Turbo Drive exige
        // para re-renderizar en vez de exigir una redirección.
        return $this->render('usd_rate/new.html.twig', [
            'form' => $form,
        ]);
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