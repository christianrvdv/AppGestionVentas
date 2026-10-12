<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Form\PasswordChangeFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cambio de la contraseña propia (/cambiar-password, Fase 6.1).
 *
 * Es el destino de EnforcePasswordChangeListener: un usuario con el
 * flag must_change_password (reset desde el panel admin) no puede usar
 * el resto de la app hasta completar este formulario. También funciona
 * de forma voluntaria para cualquier usuario autenticado.
 *
 * Sin RequireTenantListener en el camino: la ruta está blanqueada ahí,
 * porque debe poder usarse con cualquier negocio activo (o sin ninguno,
 * como el super_admin).
 */
#[Route('/cambiar-password')]
final class PasswordChangeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('', name: 'app_password_change', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user instanceof AppUser) {
            throw $this->createAccessDeniedException('Debe iniciar sesión para cambiar su contraseña.');
        }

        $form = $this->createForm(PasswordChangeFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $current = (string) $form->get('currentPassword')->getData();
            $new = (string) $form->get('newPassword')->getData();
            $confirm = (string) $form->get('confirmPassword')->getData();

            if (!$this->passwordHasher->isPasswordValid($user, $current)) {
                $form->get('currentPassword')->addError(new FormError('La contraseña actual no es válida.'));
            } elseif ($new !== $confirm) {
                $form->get('confirmPassword')->addError(new FormError('Las contraseñas nuevas no coinciden.'));
            } else {
                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $new));
                $user->setMustChangePassword(false);
                $this->em->flush();

                $this->addFlash('success', 'Contraseña actualizada. Ya puede entrar con la nueva.');

                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->render('password/change.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
