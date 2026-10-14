<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Repository\Contract\TenantRepositoryInterface;
use App\Service\Admin\AdminMetricsService;
use App\Service\Admin\ImpersonationService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Listado, ficha, activación/desactivación e impersonation de negocios
 * (Fases 6.1 y 6.2).
 *
 * El super_admin corre con el SQLFilter deshabilitado (ADR-0016), así
 * que estas vistas son la ventana a TODOS los tenants. Cualquier dato
 * que se calcule por negocio recibe el tenantId EXPLÍCITO en la
 * cláusula (contadores por tanda, métricas de la ficha): no existe
 * filtro que nos salve, el parámetro ES el aislamiento.
 *
 * La única excepción es impersonate (ADR-0019): al entrar a un negocio,
 * el filtro se RE-HABILITA con ese tenant para el resto de la sesión.
 */
#[Route('/admin/tenants', name: 'app_admin_tenant_')]
final class AdminTenantController extends AbstractController
{
    private const VALID_ESTADOS = ['activo', 'inactivo'];

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantMembershipRepositoryInterface $memberships,
        private readonly InvestmentRepositoryInterface $investments,
        private readonly SaleRepositoryInterface $sales,
        private readonly AdminMetricsService $metrics,
        private readonly ImpersonationService $impersonationService,
        private readonly PaginatorInterface $paginator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $estado = $request->query->get('estado');
        $estado = \is_string($estado) && \in_array($estado, self::VALID_ESTADOS, true) ? $estado : null;
        $isActive = null === $estado ? null : 'activo' === $estado;

        $search = $request->query->get('q');
        $q = \is_string($search) && '' !== trim($search) ? trim($search) : null;

        $pagination = $this->paginator->paginate(
            $this->tenants->createPaginatedQueryBuilder($isActive, $q),
            $request->query->getInt('page', 1),
            10
        );

        /** @var list<int> $ids */
        $ids = array_map(
            static fn (Tenant $tenant): int => $tenant->getId(),
            $pagination->getItems()
        );

        // Una consulta por tipo para TODA la página: sin N+1 y con el
        // tenant pedido explícitamente en cada cláusula.
        return $this->render('admin/tenant/index.html.twig', [
            'pagination' => $pagination,
            'current_estado' => $estado,
            'current_q' => $q,
            'counts' => [
                'users' => $this->memberships->countByTenants($ids),
                'investments' => $this->investments->countByTenants($ids),
                'sales' => $this->sales->countByTenants($ids),
            ],
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $tenant = $this->tenants->findById($id);

        if (null === $tenant) {
            throw $this->createNotFoundException('El negocio no existe.');
        }

        return $this->render('admin/tenant/show.html.twig', [
            'tenant' => $tenant,
            'metrics' => $this->metrics->tenantMetrics($id),
            'members' => $this->memberships->findByTenant($tenant),
        ]);
    }

    #[Route('/{id}/toggle-active', name: 'toggle_active', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleActive(Request $request, int $id): Response
    {
        $tenant = $this->tenants->findById($id);

        if (null === $tenant) {
            throw $this->createNotFoundException('El negocio no existe.');
        }

        $token = $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('admin_tenant_toggle', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_tenant_show', ['id' => $id]);
        }

        $tenant->setIsActive(!$tenant->isActive());
        $this->em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Negocio "%s" %s.',
                $tenant->getName(),
                $tenant->isActive() ? 'habilitado' : 'deshabilitado'
            )
        );

        return $this->redirectToRoute('app_admin_tenant_show', ['id' => $id]);
    }

    /**
     * Entra al negocio en modo soporte (impersonation, ADR-0019).
     *
     * No cambia el usuario ni su token: solo se marca en la sesión que el
     * super_admin está viendo ESTE negocio, y con la marca puesta el
     * TenantFilter se habilita con ese tenant. Se redirige al dashboard,
     * que ya mostrará los datos del negocio impersonado.
     */
    #[Route('/{id}/impersonate', name: 'impersonate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function impersonate(Request $request, int $id): Response
    {
        $tenant = $this->tenants->findById($id);

        if (null === $tenant) {
            throw $this->createNotFoundException('El negocio no existe.');
        }

        $token = $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('admin_impersonate', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_tenant_show', ['id' => $id]);
        }

        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw $this->createAccessDeniedException();
        }

        $this->impersonationService->start($tenant, $user);

        return $this->redirectToRoute('app_dashboard');
    }
}
