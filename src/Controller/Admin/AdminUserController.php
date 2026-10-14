<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AppUser;
use App\Entity\TenantMembership;
use App\Exception\Domain\MembershipViolationException;
use App\Repository\AppUserRepository;
use App\Repository\Contract\TenantMembershipRepositoryInterface;
use App\Repository\Contract\TenantRepositoryInterface;
use App\Service\Admin\MembershipManagementService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Listado, ficha, activación/desactivación y reset de contraseñas de
 * usuarios (Fase 6.1).
 *
 * El listado es GLOBAL (super_admin sin SQLFilter) y filtra por negocio
 * con el tenantId EXPLÍCITO. El filtro de rol toca la columna jsonb
 * `roles` vía una pre-lookup DBAL en el repositorio, y el valor se
 * valida contra la allow-list de abajo ANTES de llegar a la BD: nada
 * que el usuario escriba llega crudo a una consulta.
 *
 * Se tipa contra el repositorio CONCRETO: su puerto
 * (AppUserRepositoryInterface) está blindado por un test de reflexión
 * (ADR-0015) que impide agregarle lookups nuevos; las consultas del
 * panel viven en la clase concreta.
 */
#[Route('/admin/users', name: 'app_admin_user_')]
final class AdminUserController extends AbstractController
{
    /**
     * Roles que pueden estar guardados en app_user.roles. El de
     * membresía (TenantMembership) es otra cosa y no se filtra aquí.
     */
    private const VALID_ROLES = ['ROLE_ADMIN', 'ROLE_USER', 'ROLE_SUPER_ADMIN'];

    public function __construct(
        private readonly AppUserRepository $users,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantMembershipRepositoryInterface $memberships,
        private readonly MembershipManagementService $membershipManagement,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->get('q');
        $q = \is_string($search) && '' !== trim($search) ? trim($search) : null;

        $tenantId = $request->query->getInt('tenant', 0);
        $tenantId = $tenantId > 0 ? $tenantId : null;

        $role = $request->query->get('rol');
        $role = \is_string($role) && \in_array($role, self::VALID_ROLES, true) ? $role : null;

        $pagination = $this->paginator->paginate(
            $this->users->createPaginatedQueryBuilder($q, $tenantId, $role),
            $request->query->getInt('page', 1),
            10
        );

        return $this->render('admin/user/index.html.twig', [
            'pagination' => $pagination,
            'current_q' => $q,
            'current_tenant' => $tenantId,
            'current_rol' => $role,
            'tenants' => $this->tenants->findAll(),
            'roles' => self::VALID_ROLES,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $user = $this->users->findById($id);

        if (null === $user) {
            throw $this->createNotFoundException('El usuario no existe.');
        }

        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
            'memberships' => $this->memberships->findByUser($user),
            'tenants' => $this->tenants->findAll(),
        ]);
    }

    #[Route('/{id}/toggle-active', name: 'toggle_active', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleActive(Request $request, int $id): Response
    {
        $user = $this->requireUser($id);

        if (!$this->isCsrfTokenValid('admin_user_toggle', $this->csrfToken($request))) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
        }

        $user->setIsActive(!$user->isActive());
        $this->em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Cuenta de %s %s. %s',
                $user->getEmail(),
                $user->isActive() ? 'activada' : 'desactivada',
                $user->isActive()
                    ? 'Ya puede iniciar sesión de nuevo.'
                    : 'No podrá iniciar sesión mientras siga así.'
            )
        );

        return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
    }

    /**
     * Reset de contraseña (decisión del panel): genera una temporal, la
     * muestra UNA vez en el flash y deja el hash nuevo con el flag
     * must_change_password activo, de modo que el próximo login del
     * usuario termine en /cambiar-password
     * (EnforcePasswordChangeListener).
     */
    #[Route('/{id}/reset-password', name: 'reset_password', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resetPassword(Request $request, int $id): Response
    {
        $user = $this->requireUser($id);

        if (!$this->isCsrfTokenValid('admin_user_reset', $this->csrfToken($request))) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
        }

        // Hex de 8 bytes: 16 caracteres sin signos raros, fáciles de
        // copiar/pegar y seguros de pasar por un formulario.
        $temporaryPassword = bin2hex(random_bytes(8));

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $temporaryPassword));
        $user->setMustChangePassword(true);
        $this->em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Contraseña temporal para %s: %s. Deberá cambiarla en el próximo inicio de sesión.',
                $user->getEmail(),
                $temporaryPassword
            )
        );

        return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
    }

    /**
     * Agrega el usuario a un negocio existente con un rol de membresía
     * (Fase 6.2, ADR-0019). El duplicado y el rol se validan en el
     * servidor (MembershipManagementService): el formulario solo acota las
     * opciones.
     */
    #[Route('/{id}/memberships', name: 'add_membership', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addMembership(Request $request, int $id): Response
    {
        $user = $this->requireUser($id);

        if (!$this->isCsrfTokenValid('admin_user_add_membership', $this->csrfToken($request))) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
        }

        $tenantId = $request->request->getInt('tenant', 0);
        $tenant = $tenantId > 0 ? $this->tenants->findById($tenantId) : null;

        if (null === $tenant) {
            $this->addFlash('error', 'El negocio elegido no existe.');

            return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
        }

        $role = $request->request->get('role');
        $role = \is_string($role) ? $role : TenantMembership::ROLE_USER;

        try {
            $this->membershipManagement->add($user, $tenant, $role);
        } catch (MembershipViolationException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
        }

        $this->addFlash(
            'success',
            sprintf('%s ahora también pertenece a "%s".', $user->getEmail(), $tenant->getName())
        );

        return $this->redirectToRoute('app_admin_user_show', ['id' => $id]);
    }

    private function requireUser(int $id): AppUser
    {
        $user = $this->users->findById($id);

        if (null === $user) {
            throw $this->createNotFoundException('El usuario no existe.');
        }

        return $user;
    }

    private function csrfToken(Request $request): ?string
    {
        $token = $request->request->get('_csrf_token');

        return \is_string($token) ? $token : null;
    }
}
