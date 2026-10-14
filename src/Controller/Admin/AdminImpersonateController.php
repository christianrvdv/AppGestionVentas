<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\ImpersonationSession;
use App\Service\Admin\ImpersonationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Salida del impersonation de tenant (Fase 6.2, ADR-0019).
 *
 * La entrada (impersonate) vive en AdminTenantController porque es una
 * acción de la ficha de un negocio concreto; la salida es una ruta fija,
 * igual que el banner que la invoca desde cualquier página.
 *
 * Todas las rutas ^/admin exigen ROLE_SUPER_ADMIN (security.yaml), así
 * que aquí solo puede llegar un super_admin.
 */
#[Route('/admin/impersonate', name: 'app_admin_impersonate_')]
final class AdminImpersonateController extends AbstractController
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
        private readonly ImpersonationSession $impersonationSession,
    ) {
    }

    /**
     * Cierra la sesión de soporte: termina la auditoría, limpia la marca
     * de la sesión y restaura el tenant anterior (normalmente ninguno).
     */
    #[Route('/stop', name: 'stop', methods: ['POST'])]
    public function stop(Request $request): Response
    {
        $token = $request->request->get('_csrf_token');

        if (!\is_string($token) || !$this->isCsrfTokenValid('admin_impersonate_stop', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_admin_index');
        }

        // Sin marca no hay nada que cerrar (una petición repetida o una
        // sesión ya terminada): se responde igual, sin tocar la BD.
        if ($this->impersonationSession->isActive()) {
            $this->impersonationService->stop();
            $this->addFlash('success', 'Se cerró la vista de soporte: ya no estás viendo ningún negocio.');
        }

        return $this->redirectToRoute('app_admin_index');
    }
}
