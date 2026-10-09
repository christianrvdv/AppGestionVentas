<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Security\ActiveTenantSession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Selector de negocio activo (ADR-0015).
 *
 * Aterriza aquí quien se loguea con más de una membresía
 * (TenantSessionListener) o quien pide cambiar de negocio desde el nav.
 *
 * La lista sale del repositorio de membresías (consultas con el
 * TenantFilter deshabilitado a propósito: hay que ver TODOS los
 * negocios del usuario, incluido el actual). El POST de cambio valida
 * la membresía contra el usuario de la sesión (findByIdAndUser) antes
 * de escribir la clave: un id ajeno simplemente no existe, no se
 * confía en un parámetro del request.
 */
#[Route('/select-tenant')]
final class SelectTenantController extends AbstractController
{
    public function __construct(
        private readonly TenantMembershipRepositoryInterface $membershipRepository,
        private readonly ActiveTenantSession $activeTenantSession,
    ) {
    }

    #[Route('', name: 'app_select_tenant', methods: ['GET'])]
    public function index(): Response
    {
        $memberships = $this->membershipRepository->findByUser($this->requireAppUser());

        if ([] === $memberships) {
            return $this->redirectToRoute('app_onboarding');
        }

        return $this->render('tenant/select.html.twig', [
            'memberships' => $memberships,
            'active_tenant_id' => $this->activeTenantSession->getTenantId(),
        ]);
    }

    #[Route('/{membershipId}', name: 'app_select_tenant_switch', requirements: ['membershipId' => '\d+'], methods: ['POST'])]
    public function switch(Request $request, int $membershipId): RedirectResponse
    {
        $user = $this->requireAppUser();

        $token = $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('select_tenant', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_select_tenant');
        }

        // La condición por usuario va EN LA QUERY: un membershipId ajeno
        // no resuelve y cae en el 404, sin depender de una comprobación
        // "a ojo" en este controller.
        $membership = $this->membershipRepository->findByIdAndUser($membershipId, $user->getId());

        if (null === $membership) {
            throw $this->createNotFoundException('El negocio no existe o no te pertenece.');
        }

        if (!$membership->getTenant()->isActive()) {
            $this->addFlash('error', 'Ese negocio está deshabilitado.');

            return $this->redirectToRoute('app_select_tenant');
        }

        $this->activeTenantSession->setTenantId($membership->getTenant()->getId());

        return $this->redirectToRoute('app_dashboard');
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
