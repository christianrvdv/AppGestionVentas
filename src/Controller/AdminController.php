<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Panel de administración global (ADR-0016).
 *
 * Destino de la whitelist /admin de RequireTenantListener: el rol
 * ROLE_SUPER_ADMIN opera SIN negocio activo, así que esta única ruta
 * es la que no exige tenant (el resto del panel de administración de
 * datos de negocio queda para una fase posterior).
 *
 * El acceso está blindado en security.yaml (^/admin →
 * ROLE_SUPER_ADMIN): una cuenta normal recibe 403 aunque el listener
 * permita pasar la request sin tenant.
 */
#[Route('/admin', name: 'app_admin_')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/index.html.twig');
    }
}
