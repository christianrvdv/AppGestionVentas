<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\Contract\AppUserRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Invariante de identidad global de AppUserRepositoryInterface
 * (ADR-0015): el email es único en todo el sistema, así que NINGÚN
 * método de este repositorio recibe $tenantId.
 *
 * Antes de la Fase 3.5 la regla era la contraria (todo lookup era por
 * tenant); el test se invirtió junto con el modelo. Si alguien
 * reintroduce un método parametrizado por tenant, la suite falla antes
 * de que el dato pueda aislarse mal.
 *
 * La Fase 3.6 (ADR-0016) agregó findAll(): la visión global del
 * super_admin lista IDENTIDADES de aplicación (no datos de negocio) y
 * tampoco recibe $tenantId — el invariante de "sin tenant en la firma"
 * se mantiene intacto.
 *
 * Test de reflexión a propósito: no ejecuta Doctrine, vive en la suite
 * Unit y no necesita kernel.
 */
final class AppUserRepositoryInterfaceTest extends TestCase
{
    /** @var string[] */
    private const EXPECTED_METHODS = ['findAll', 'findById', 'findOneByEmail'];

    /**
     * Lookups de login/identidad, definidos por ADR-0015.
     *
     * @var string[]
     */
    private const IDENTITY_LOOKUPS = ['findById', 'findOneByEmail'];

    public function testExposesExactlyTheGlobalIdentityLookups(): void
    {
        $interface = new \ReflectionClass(AppUserRepositoryInterface::class);

        $this->assertTrue($interface->isInterface());

        $names = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $interface->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($names);
        $expected = self::EXPECTED_METHODS;
        sort($expected);

        $this->assertSame(
            $expected,
            $names,
            'El contrato de usuarios es global: findOneByEmail + findById (ADR-0015) '
            .'y findAll (ADR-0016). Si hace otro lookup, abrir un ADR antes de agregarlo.'
        );
    }

    public function testNoMethodReceivesTenantId(): void
    {
        $interface = new \ReflectionClass(AppUserRepositoryInterface::class);

        foreach ($interface->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertStringNotContainsString(
                    'tenant',
                    $parameter->getName(),
                    sprintf(
                        '%s() no debe recibir nada relacionado con tenant: el usuario es global (ADR-0015).',
                        $method->getName()
                    )
                );
            }
        }
    }

    public function testLookupsAreDocumentedAsGlobalIdentity(): void
    {
        foreach (self::IDENTITY_LOOKUPS as $name) {
            $method = new \ReflectionMethod(AppUserRepositoryInterface::class, $name);
            $doc = (string) $method->getDocComment();

            $this->assertNotSame('', $doc, sprintf('%s() debe llevar docblock: justifica por qué no recibe tenant.', $name));
            $this->assertStringContainsString(
                'ADR-0015',
                $doc,
                sprintf('El docblock de %s() debe remitir al ADR que define la identidad global.', $name)
            );
        }
    }

    public function testFindAllIsDocumentedBySuperAdminAdr(): void
    {
        $method = new \ReflectionMethod(AppUserRepositoryInterface::class, 'findAll');
        $doc = (string) $method->getDocComment();

        $this->assertNotSame('', $doc, 'findAll() debe llevar docblock: justifica la visión global.');
        $this->assertStringContainsString(
            'ADR-0016',
            $doc,
            'El docblock de findAll() debe remitir al ADR que autoriza la visión global del super_admin.'
        );
    }
}
