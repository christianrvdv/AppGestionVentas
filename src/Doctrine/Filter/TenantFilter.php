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
 *   - Si tenantId no es un entero positivo (null, '', '0', no numérico),
 *     restringe a 0 filas (caso "sin tenant activo").
 *
 * [FIX-5] El valor SIEMPRE se castea a int y se interpola con %d, nunca
 * con %s: así un llamador que pase un string arbitrario no puede inyectar
 * SQL y la rama "sin tenant" queda cubierta por <= 0 (antes se comparaba
 * con la cadena literal '0', que no cubría el int 0).
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

        // [FIX-5] ORM 3 / DBAL 4: getParameter() devuelve el valor YA
        // ENTRECOMILLADO (Connection::quote(), p. ej. "'5'"). Un cast
        // directo de "'5'" a int daría 0 y bloquearía todas las filas,
        // así que primero se quitan las comillas simples envolventes.
        //
        // El cast a int es también la defensa contra inyección SQL: un
        // valor no numérico ("1 OR 1=1") se trunca a su prefijo entero
        // y, con %d, jamás se interpola como SQL crudo. Cubre además
        // null, '' y '0' (antes: comparación frágil === '0').
        $tenantIdInt = (int) trim($tenantId, "'");

        if ($tenantIdInt <= 0) {
            return '1 = 0';
        }

        $association = $targetEntity->getAssociationMapping('tenant');
        $joinColumns = $association['joinColumns'] ?? [];

        if ($joinColumns === []) {
            return '';
        }

        $columnName = $joinColumns[0]['name'];

        return sprintf('%s.%s = %d', $targetTableAlias, $columnName, $tenantIdInt);
    }
}
