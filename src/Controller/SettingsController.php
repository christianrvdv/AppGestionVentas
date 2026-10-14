<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppSetting;
use App\Entity\Tenant;
use App\Form\SettingsFormType;
use App\Service\SettingsService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/configuracion')]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SettingsService $settingsService,
    ) {}

    #[Route('', name: 'app_settings_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->getTenantOr404();

        $formData = [
            'tenant_name' => $tenant->getName(),
            'tenant_slug' => $tenant->getSlug(),
            'base_currency' => $this->settingsService->getBaseCurrency(),
            'currency_scale' => $this->settingsService->getCurrencyScale(),
            'default_margin_pct' => $this->settingsService->getDefaultMarginPct(),
            'recovery_mode' => $this->settingsService->getRecoveryModeDefault(),
            'usd_control_enabled' => $this->settingsService->isUsdControlEnabled(),
        ];

        $form = $this->createForm(SettingsFormType::class, $formData);

        return $this->render('settings/index.html.twig', [
            'form' => $form->createView(),
            'tenant' => $tenant,
        ]);
    }

    #[Route('', name: 'app_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $tenant = $this->getTenantOr404();

        $formData = [
            'tenant_name' => $tenant->getName(),
            'tenant_slug' => $tenant->getSlug(),
            'base_currency' => $this->settingsService->getBaseCurrency(),
            'currency_scale' => $this->settingsService->getCurrencyScale(),
            'default_margin_pct' => $this->settingsService->getDefaultMarginPct(),
            'recovery_mode' => $this->settingsService->getRecoveryModeDefault(),
            'usd_control_enabled' => $this->settingsService->isUsdControlEnabled(),
        ];

        $form = $this->createForm(SettingsFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{
                tenant_name: string,
                base_currency: string,
                currency_scale: int,
                default_margin_pct: string,
                recovery_mode: string,
                usd_control_enabled: bool
            } $data */
            $data = $form->getData();

            // Verificar si base_currency cambió y hay inversiones existentes
            $currentBaseCurrency = $this->settingsService->getBaseCurrency();
            $newBaseCurrency = strtoupper($data['base_currency']);

            $hasInvestments = false;
            if ($currentBaseCurrency !== $newBaseCurrency) {
                $hasInvestments = $this->hasExistingInvestments($tenant->getId());
            }

            $values = [
                AppSetting::KEY_BASE_CURRENCY => $newBaseCurrency,
                AppSetting::KEY_CURRENCY_SCALE => (string) $data['currency_scale'],
                AppSetting::KEY_DEFAULT_MARGIN_PCT => (string) $data['default_margin_pct'],
                AppSetting::KEY_RECOVERY_MODE => $data['recovery_mode'],
                AppSetting::KEY_USD_CONTROL_ENABLED => $data['usd_control_enabled'] ? 'true' : 'false',
            ];

            $this->settingsService->setMany($values);

            if ($hasInvestments) {
                $this->addFlash('warning', sprintf(
                    'La moneda base cambió de %s a %s. Las inversiones existentes conservan su moneda original. Los reportes pueden mostrar valores mezclados.',
                    $currentBaseCurrency,
                    $newBaseCurrency
                ));
            } else {
                $this->addFlash('success', 'Configuración guardada correctamente.');
            }

            return $this->redirectToRoute('app_settings_index');
        }

        // FormInterface en vez de createView(): render() fija 422 solo
        // cuando el form es inválido, que es lo que Turbo Drive exige
        // para re-renderizar en vez de exigir una redirección.
        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'tenant' => $tenant,
        ]);
    }

    private function getTenantOr404(): Tenant
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw $this->createAccessDeniedException('La sesión no tiene un negocio activo.');
        }

        $tenant = $this->settingsService->getTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant no encontrado.');
        }

        return $tenant;
    }

    private function hasExistingInvestments(int $tenantId): bool
    {
        $entityManager = $this->settingsService->getEntityManager();
        $count = $entityManager->createQueryBuilder()
            ->select('count(i.id)')
            ->from('App\Entity\Investment', 'i')
            ->where('i.tenant = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}