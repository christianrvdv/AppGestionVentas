<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\Contract\AppUserRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Invariante multi-tenant de AppUserRepositoryInterface: todo método
 * recibe $tenantId explícito, salvo findById() (el único lookup
 * cross-tenant permitido, reservado a AppUserProvider::refreshUser).
 *
 * Test de reflexión a propósito: si alguien agrega un método sin
 * $tenantId, o le quita la documentación que justifica la excepción a
 * findById(), la suite falla antes de que el dato pueda filtrarse.
 */
final class AppUserRepositoryInterfaceTest extends TestCase
{
    public function testEveryPublicMethodReceivesIntTenantIdExceptFindById(): void
    {
        $interface = new \ReflectionClass(AppUserRepositoryInterface::class);

        $this->assertTrue($interface->isInterface());

        foreach ($interface->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === 'findById') {
                $this->assertFalse(
                    $this->hasTenantIdParameter($method),
                    'findById() es la ÚNICA excepción autorizada: no debe recibir $tenantId. '
                    . 'Si hace falta otro lookup cross-tenant, abrir un ADR antes de agregarlo.'
                );

                continue;
            }

            $this->assertTrue(
                $this->hasTenantIdParameter($method),
                sprintf(
                    '%s() debe recibir int $tenantId: todo lookup de usuarios es por tenant.',
                    $method->getName()
                )
            );
        }
    }

    public function testFindByIdIsDocumentedAsTheOnlyCrossTenantLookup(): void
    {
        $method = new \ReflectionMethod(AppUserRepositoryInterface::class, 'findById');

        $doc = (string) $method->getDocComment();

        $this->assertNotSame('', $doc, 'findById() debe llevar docblock: es la excepción al invariante.');
        $this->assertStringContainsString(
            'refreshUser',
            $doc,
            'El docblock de findById() debe explicar que la usa AppUserProvider::refreshUser.'
        );
        $this->assertTrue(
            str_contains($doc, 'cross-tenant') || str_contains($doc, 'SIN filtro de tenant'),
            'El docblock de findById() debe declararlo como lookup cross-tenant.'
        );
    }

    private function hasTenantIdParameter(\ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() !== 'tenantId') {
                continue;
            }

            $type = $parameter->getType();

            return $type instanceof \ReflectionNamedType
                && $type->getName() === 'int';
        }

        return false;
    }
}
