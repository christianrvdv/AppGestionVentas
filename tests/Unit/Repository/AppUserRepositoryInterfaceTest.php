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
 * Test de reflexión a propósito: no ejecuta Doctrine, vive en la suite
 * Unit y no necesita kernel.
 */
final class AppUserRepositoryInterfaceTest extends TestCase
{
    /** @var string[] */
    private const EXPECTED_METHODS = ['findOneByEmail', 'findById'];

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
            'El contrato de usuarios es global: exactamente findOneByEmail + findById. '
            .'Si hace otro lookup, abrir un ADR antes de agregarlo.'
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
        foreach (self::EXPECTED_METHODS as $name) {
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
}
