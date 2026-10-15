<?php

declare(strict_types=1);

namespace App\Tests\Functional\Settings;

use App\Entity\AppSetting;
use App\Entity\AppUser;
use App\Entity\Investment;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Service\SettingsService;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fase 2 (rediseño hub): los errores de campo se pintan con la clase
 * semántica del sistema `form-error` en vez de la utility cruda
 * `text-danger`; los asserts apuntan al selector del sistema.
 * El flash warning sigue renderizándose como success en base.html.twig
 * (fuera de alcance de la Fase 2) y sus asserts no cambian.
 */
final class SettingsControllerTest extends FunctionalTestCase
{
    private KernelBrowser $client;
    private Tenant $tenant;
    private AppUser $user;

    private function setupClientAndLogin(): void
    {
        $this->client = $this->createSharedKernelClient();
        ['tenant' => $this->tenant, 'user' => $this->user] = $this->seedDemoTenantAndAdmin();
        $this->loginAsDemoAdmin($this->client);
    }

    private function createSettings(): void
    {
        // Re-fetch tenant to ensure it's managed by current entity manager
        $tenant = $this->em->getReference(Tenant::class, $this->tenant->getId());
        
        $settings = [
            AppSetting::KEY_DEFAULT_MARGIN_PCT => '40.00',
            AppSetting::KEY_BASE_CURRENCY => 'ARS',
            AppSetting::KEY_RECOVERY_MODE => 'PER_PRODUCT',
            AppSetting::KEY_CURRENCY_SCALE => '2',
            AppSetting::KEY_USD_CONTROL_ENABLED => 'false',
        ];

        foreach ($settings as $key => $value) {
            $setting = new AppSetting();
            $setting->setTenant($tenant);
            $setting->setSettingKey($key);
            $setting->setSettingValue($value);
            $this->em->persist($setting);
        }
        $this->em->flush();
    }

    private function createInvestment(): Investment
    {
        // Re-fetch tenant and user to ensure they're managed by current entity manager
        $tenant = $this->em->getReference(Tenant::class, $this->tenant->getId());
        $user = $this->em->getReference(AppUser::class, $this->user->getId());
        
        $investment = new Investment();
        $investment->setTenant($tenant);
        $investment->setCreatedBy($user);
        $investment->setCode('INV-TEST');
        $investment->setInvestmentDate(new \DateTimeImmutable('2026-01-01'));
        $investment->setBaseCurrency('ARS');
        $investment->setUsdRateSnapshot('1000.0000');
        $investment->setCurrentUsdRate('1000.0000');
        $investment->setTotalMerchandiseCost('1000.00');
        $investment->setTotalExpenses('0.00');
        $investment->setTotalInvestment('1000.00');
        $investment->setStatus(Investment::STATUS_OPEN);
        $investment->setRecoveryMode(Investment::RECOVERY_PER_PRODUCT);

        $this->em->persist($investment);
        $this->em->flush();

        return $investment;
    }

    public function test_get_settings_page(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $this->client->request('GET', '/configuracion');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Configuración del tenant');
        $this->assertSelectorExists('form');
    }

    public function test_save_default_margin_pct(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[default_margin_pct]'] = '50.00';
        $this->client->submit($form);

        $this->assertResponseRedirects('/configuracion');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-success', 'Configuración guardada correctamente.');

        // Verify setting was saved
        $settingsService = static::getContainer()->get(SettingsService::class);
        $this->assertSame('50', $settingsService->getDefaultMarginPct());
    }

    public function test_save_recovery_mode_default(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[recovery_mode]'] = 'INVESTMENT_FIRST';
        $this->client->submit($form);

        $this->assertResponseRedirects('/configuracion');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $settingsService = static::getContainer()->get(SettingsService::class);
        $this->assertSame('INVESTMENT_FIRST', $settingsService->getRecoveryModeDefault());
    }

    public function test_save_currency_scale(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[currency_scale]'] = '3';
        $this->client->submit($form);

        $this->assertResponseRedirects('/configuracion');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $settingsService = static::getContainer()->get(SettingsService::class);
        $this->assertSame(3, $settingsService->getCurrencyScale());
    }

    public function test_save_usd_control_enabled(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[usd_control_enabled]'] = '1';
        $this->client->submit($form);

        $this->assertResponseRedirects('/configuracion');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $settingsService = static::getContainer()->get(SettingsService::class);
        $this->assertTrue($settingsService->isUsdControlEnabled());
    }

    public function test_validation_default_margin_pct_range(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[default_margin_pct]'] = '150'; // > 100
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('.form-error', 'no puede superar 100%');
    }

    public function test_validation_default_margin_pct_negative(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[default_margin_pct]'] = '-10';
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('.form-error', 'no puede ser negativo');
    }

    public function test_validation_currency_scale_range(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[currency_scale]'] = '5'; // > 4
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('.form-error', 'Máximo 4 decimales permitidos');
    }

    public function test_validation_currency_scale_negative(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[currency_scale]'] = '-1';
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('.form-error', 'no pueden ser negativos');
    }

    public function test_validation_base_currency_format(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[base_currency]'] = 'ars'; // lowercase
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('.form-error', '3 letras mayúsculas');
    }

    public function test_csrf_invalid_returns_403(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $this->client->request('POST', '/configuracion', [
            'settings_form' => [
                'tenant_name' => 'Demo',
                'base_currency' => 'ARS',
                'currency_scale' => 2,
                'default_margin_pct' => '50.00',
                'recovery_mode' => 'PER_PRODUCT',
                'usd_control_enabled' => false,
                '_token' => 'invalid-csrf-token',
            ],
        ]);

        // Form CSRF validation fails and re-renders form (does not redirect)
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorExists('form');
    }

    public function test_change_base_currency_with_existing_investments_shows_warning(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();
        $this->createInvestment();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[base_currency]'] = 'USD';
        $this->client->submit($form);

        $this->assertResponseRedirects('/configuracion');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Should show a warning flash painted as alert-warning (not success)
        $this->assertSelectorTextContains('.alert-warning', 'La moneda base cambió de ARS a USD');
        $this->assertSelectorTextContains('.alert-warning', 'inversiones existentes conservan su moneda original');
    }

    public function test_change_base_currency_without_investments_shows_success(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();
        // No investments created

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[base_currency]'] = 'USD';
        $this->client->submit($form);

        $this->assertResponseRedirects('/configuracion');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Should show success flash (no warning about existing investments)
        $this->assertSelectorTextContains('.alert-success', 'Configuración guardada correctamente.');
    }

    public function test_tenant_name_required(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[tenant_name]'] = '';
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('.form-error', 'El nombre del tenant es obligatorio');
    }

    public function test_slug_is_readonly(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();

        // Slug field should be disabled
        $this->assertTrue($form['settings_form[tenant_slug]']->isDisabled());
    }

    public function test_multiple_settings_saved_at_once(): void
    {
        $this->setupClientAndLogin();
        $this->createSettings();

        $crawler = $this->client->request('GET', '/configuracion');
        $form = $crawler->selectButton('Guardar configuración')->form();
        $form['settings_form[default_margin_pct]'] = '35.50';
        $form['settings_form[recovery_mode]'] = 'INVESTMENT_FIRST';
        $form['settings_form[currency_scale]'] = '3';
        $form['settings_form[base_currency]'] = 'USD';
        $form['settings_form[usd_control_enabled]'] = '1';
        $this->client->submit($form);

        $this->assertResponseRedirects('/configuracion');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $settingsService = static::getContainer()->get(SettingsService::class);
        $this->assertSame('35.5', $settingsService->getDefaultMarginPct());
        $this->assertSame('INVESTMENT_FIRST', $settingsService->getRecoveryModeDefault());
        $this->assertSame(3, $settingsService->getCurrencyScale());
        $this->assertSame('USD', $settingsService->getBaseCurrency());
        $this->assertTrue($settingsService->isUsdControlEnabled());
    }
}