<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Form\OnboardingFormType;
use App\Repository\Contract\TenantRepositoryInterface;
use App\Security\ActiveTenantSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alta del primer negocio de un usuario (onboarding, ADR-0015).
 *
 * Aterriza aquí quien se loguea sin membresías (TenantSessionListener)
 * o quien pide /onboarding directamente. Crea el Tenant, la membresía
 * del usuario como ROLE_ADMIN (negocio propio) y ACTIVA el negocio en
 * la sesión: sin ese paso, RequireTenantListener redirigiría a este
 * mismo formulario en el siguiente request.
 *
 * El formulario es público-lectura pero la ruta requiere sesión
 * (access_control ^/ pide ROLE_USER): no se puede crear un negocio sin
 * usuario, y el usuario activo sale de la sesión, nunca del request.
 */
#[Route('/onboarding')]
final class OnboardingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly ActiveTenantSession $activeTenantSession,
    ) {
    }

    #[Route('', name: 'app_onboarding', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->requireAppUser();

        $tenant = new Tenant();
        $form = $this->createForm(OnboardingFormType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $slug = $this->resolveSlug($tenant, $form->get('slug')->getData());

            if ($this->tenantRepository->findBySlug($slug) !== null) {
                $form->get('slug')->addError(
                    new FormError('Ese slug ya está en uso. Probá con otro.')
                );
            } else {
                $tenant->setSlug($slug);
                $tenant->setIsActive(true);

                $this->entityManager->persist($tenant);

                // Membresía propia: el creador es admin de su negocio.
                $membership = new TenantMembership($user, $tenant);
                $membership->setRole(TenantMembership::ROLE_ADMIN);
                $membership->setIsDefault(true);
                $user->addMembership($membership);
                $this->entityManager->persist($membership);

                $this->entityManager->flush();

                // Recién ahora existe el id: se activa en la sesión para
                // que la request siguiente arranque con tenant activo.
                $this->activeTenantSession->setTenantId($tenant->getId());

                $this->addFlash('success', sprintf('Negocio "%s" creado.', $tenant->getName()));

                return $this->redirectToRoute('app_dashboard');
            }
        }

        return $this->render('tenant/onboarding.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Slug del formulario o, si está vacío, uno derivado del nombre.
     * Normalizado a minúsculas y con guiones en lugar de separadores.
     */
    private function resolveSlug(Tenant $tenant, mixed $rawSlug): string
    {
        $slug = \is_string($rawSlug) ? trim($rawSlug) : '';

        if ('' !== $slug) {
            return $slug;
        }

        $slug = mb_strtolower(trim($tenant->getName()));
        $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', $slug);
        $slug = trim($slug, '-');

        return '' === $slug ? 'negocio' : mb_substr($slug, 0, 80);
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
