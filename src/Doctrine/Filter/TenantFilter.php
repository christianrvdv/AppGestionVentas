<?php

declare(strict_types=1);

namespace App\Doctrine\Filter;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Filtro SQL global que restringe toda consulta a las entidades con
 * asociación 'tenant' al tenant activo.
 *
 * Es defensa en profundidad: aunque los repositorios ya filtran,
 * cualquier DQL nueva que olvide el filtro queda protegida.
 *
 * Comportamiento:
 *   - Si la entidad no tiene asociación 'tenant', no aplica restricción
 *     (p.ej. Tenant mismo).
 *   - Si el parámetro 'tenantId' no está definido, no aplica restricción
 *     (útil para migraciones y comandos CLI cross-tenant).
 *   - Si tenantId es null, '', o '0', restringe a 0 filas
 *     (caso "sin tenant activo").
 */
final class TenantFilter extends SQLFilter
{
    public const NAME = 'tenant_filter';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->hasAssociation('tenant')) {
            return '';
        }

        if (!$this->hasParameter('tenantId')) {
            // Sin parámetro definido: no restringimos.
            // El listener siempre lo define en contexto web.
            return '';
        }

        $tenantId = $this->getParameter('tenantId');

        // [FIX] Cubre null, cadena vacía y '0'. Sin la comprobación de
        // null, un setParameter('tenantId', null) generaría SQL inválido
        // del estilo "tenant_id = " y rompería la query.
        if ($tenantId === null || $tenantId === '' || $tenantId === '0') {
            return '1 = 0';
        }

        $association = $targetEntity->getAssociationMapping('tenant');
        $joinColumns = $association['joinColumns'] ?? [];

        if ($joinColumns === []) {
            return '';
        }

        $columnName = $joinColumns[0]['name'];

        return sprintf('%s.%s = %s', $targetTableAlias, $columnName, $tenantId);
    }
}
