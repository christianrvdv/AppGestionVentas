<?php

namespace App\Tests\Integration\Service;

use App\Service\TenantContext;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * [FIX-6] TenantContext debe reiniciarse entre requests de un worker
 * persistente: implementa ResetInterface y el autoconfigure lo etiqueta
 * con kernel.reset para que ServicesResetter lo limpie.
 */
final class TenantContextResetTest extends IntegrationTestCase
{
    public function test_tenant_context_implementa_reset_interface(): void
    {
        $this->assertInstanceOf(ResetInterface::class, $this->tenantContext);
    }

    public function test_reset_limpia_el_tenant_activo(): void
    {
        $this->tenantContext->setTenantId(42);
        $this->assertSame(42, $this->tenantContext->getTenantId());

        $this->tenantContext->reset();

        $this->assertNull($this->tenantContext->getTenantId());
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function test_services_resetter_de_la_app_reinicia_el_tenant_context(): void
    {
        $this->tenantContext->setTenantId(7);

        $resetter = static::getContainer()->get('services_resetter');
        $resetter->reset();

        $this->assertNull(
            $this->tenantContext->getTenantId(),
            'ServicesResetter debe invocar reset() de TenantContext al terminar la request.'
        );
    }
}
