<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TenantMembership;
use App\Exception\Domain\MembershipViolationException;
use App\Service\Admin\MembershipManagementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cambio de rol y baja de una membresía desde el panel admin (Fase 6.2,
 * ADR-0019).
 *
 * Las rutas ^/admin exigen ROLE_SUPER_ADMIN (security.yaml), así que
 * quien llega aquí tiene alcance global y el SQLFilter está
 * deshabilitado: la membresía se busca por PK y las invariantes (última
 * del negocio, última del usuario, último administrador) se validan en
 * MembershipManagementService, no en la vista.
 *
 * El alta vive en AdminUserController (POST /admin/users/{id}/memberships)
 * porque pertenece a la ficha del usuario.
 */
#[Route('/admin/memberships', name: 'app_admin_membership_')]
final class AdminMembershipController extends AbstractController
{
    public function __construct(
        private readonly MembershipManagementService $membershipManagement,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/change-role', name: 'change_role', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeRole(Request $request, int $id): Response
    {
        $membership = $this->requireMembership($id);
        $userId = $membership->getUser()->getId();

        $token = $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('admin_membership_change_role', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
        }

        $role = $request->request->get('role');
        if (!\is_string($role)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
        }

        try {
            $this->membershipManagement->changeRole($membership, $role);
        } catch (MembershipViolationException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
        }

        $this->addFlash('success', 'Rol actualizado.');

        return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
    }

    #[Route('/{id}/remove', name: 'remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(Request $request, int $id): Response
    {
        $membership = $this->requireMembership($id);
        $userId = $membership->getUser()->getId();

        $token = $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('admin_membership_remove', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
        }

        try {
            $this->membershipManagement->remove($membership);
        } catch (MembershipViolationException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
        }

        // El email se captura ANTES de borrar: el usuario de la membresía
        // sigue vivo, pero no hace falta recargarlo.
        $this->addFlash(
            'success',
            sprintf(
                'La membresía de %s fue quitada. El usuario se conserva; solo perdió ese acceso.',
                $membership->getUser()->getEmail()
            )
        );

        return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
    }

    private function requireMembership(int $id): TenantMembership
    {
        $membership = $this->em->find(TenantMembership::class, $id);

        if (!$membership instanceof TenantMembership) {
            throw $this->createNotFoundException('La membresía no existe.');
        }

        return $membership;
    }
}
