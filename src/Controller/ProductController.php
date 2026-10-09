<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppUser;
use App\Entity\Product;
use App\Form\ProductFormType;
use App\Repository\Contract\ProductRepositoryInterface;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRUD web de productos (Fase 3a). El tenant no se recibe nunca por
 * request: sale de la sesión y la baja es `isActive = false` (los
 * productos cuelgan de InvestmentItem con RESTRICT, no se borran).
 */
#[Route('/productos')]
final class ProductController extends AbstractController
{
    private const MAX_FILTER_LENGTH = 100;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('', name: 'app_product_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $term = $this->filterTerm($request);

        $pagination = $this->paginator->paginate(
            $this->productRepository->createPaginatedQueryBuilder($this->requireTenantId(), $term),
            $request->query->getInt('page', 1),
            10
        );

        return $this->render('product/index.html.twig', [
            'pagination' => $pagination,
            'q' => $term,
        ]);
    }

    #[Route('/nuevo', name: 'app_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $product = new Product();
        $product->setTenant($this->requireAppUser()->getTenant());

        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($product);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Producto "%s" creado.', $product->getName()));

            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/editar', name: 'app_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $product = $this->findProduct($id);

        $form = $this->createForm(ProductFormType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Producto actualizado.');

            return $this->redirectToRoute('app_product_index');
        }

        return $this->render('product/edit.html.twig', [
            'product' => $product,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/desactivar', name: 'app_product_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(int $id, Request $request): Response
    {
        $product = $this->findProduct($id);

        $token = $request->request->get('_csrf_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('submit', $token)) {
            $this->addFlash('error', 'Solicitud inválida.');

            return $this->redirectToRoute('app_product_index');
        }

        if ($product->isActive()) {
            $product->setIsActive(false);
            $this->entityManager->flush();

            $this->addFlash('success', sprintf('Producto "%s" desactivado.', $product->getName()));
        }

        return $this->redirectToRoute('app_product_index');
    }

    private function findProduct(int $id): Product
    {
        $product = $this->productRepository->findByIdAndTenant($id, $this->requireTenantId());

        if (null === $product) {
            throw $this->createNotFoundException('El producto no existe.');
        }

        return $product;
    }

    private function filterTerm(Request $request): ?string
    {
        $q = $request->query->get('q');

        if (!\is_string($q)) {
            return null;
        }

        $q = trim($q);

        return '' === $q ? null : mb_substr($q, 0, self::MAX_FILTER_LENGTH);
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
