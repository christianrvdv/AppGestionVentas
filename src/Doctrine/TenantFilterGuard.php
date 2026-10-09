<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Doctrine\Filter\TenantFilter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ejecuta un callback con el SQLFilter de tenant DESHABILITADO.
 *
 * Es el único punto del código autorizado a apagar el filtro, y existe
 * para operaciones que son POR USUARIO, no por negocio:
 *   - los lookups de AppUserProvider (el usuario de sesión se recarga
 *     antes de que exista un tenant activo en la request);
 *   - las consultas de TenantMembership (el selector /select-tenant
 *     debe listar TODOS los negocios del usuario, incluido el que se
 *     quiere cambiar).
 *
 * El filtro se restaura en `finally`, así que una excepción dentro del
 * callback no lo deja apagado para el resto de la request (importa en
 * workers persistentes, FIX-6, y en tests con kernel compartido).
 */
final class TenantFilterGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withoutTenantFilter(callable $callback): mixed
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled(TenantFilter::NAME);

        if ($wasEnabled) {
            $filters->disable(TenantFilter::NAME);
        }

        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $filters->enable(TenantFilter::NAME);
            }
        }
    }
}
