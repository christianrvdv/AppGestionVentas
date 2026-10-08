<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login (GET/POST /login) y logout.
 *
 * El logout NO tiene acción propia: la ruta /logout la genera
 * security.route_loader.logout (config/routes/security.yaml) y la maneja
 * el firewall (`_logout_main`). El template la referencia con path('_logout_main').
 */
#[Route('/login')]
final class SecurityController extends AbstractController
{
    #[Route('', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }
}
