<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\AppUser;

/**
 * Puerto de persistencia para usuarios de la aplicación.
 * Implementación Doctrine: App\Repository\AppUserRepository.
 *
 * Usuario GLOBAL (ADR-0015): el email es único en todo el sistema y la
 * tabla app_user ya no tiene tenant_id, por lo que NINGUNA consulta de
 * este repositorio recibe $tenantId. La pertenencia a negocios vive en
 * TenantMembership (ver TenantMembershipRepositoryInterface).
 *
 * Los métodos findOneByEmail y findById son, por diseño, los ÚNICOS
 * lookups de login de la capa de repositorio que ignoran el tenant
 * activo: los ejecuta exclusivamente AppUserProvider (login y refresco
 * de sesión), con el TenantFilter deshabilitado y nunca para leer datos
 * de negocio.
 *
 * findAll() es distinto: existe para la VISIÓN GLOBAL del super_admin
 * (ADR-0016) vía el comando CLI app:user:list. Lista identidades, no
 * datos de negocio, y su consumidor es el panel de administración, no
 * el flujo web por tenant.
 */
interface AppUserRepositoryInterface
{
    /**
     * Lookup de login (ADR-0015): identidad global por email.
     *
     * NO recibe $tenantId porque el email identifica al usuario en todo
     * el sistema. Verificado por test: AppUserRepositoryInterfaceTest.
     */
    public function findOneByEmail(string $email): ?AppUser;

    /**
     * ÚNICO lookup cross-tenant de la capa de repositorio (ADR-0015) —
     * no agregar otro sin un ADR que lo justifique.
     *
     * Existe exclusivamente para AppUserProvider::refreshUser(): en cada
     * request autenticada hay que recargar el usuario de la sesión por
     * id, y ese lookup corre con el TenantFilter deshabilitado porque
     * llega ANTES de que la request tenga un tenant activo en la sesión
     * (o sin él, en /select-tenant y /onboarding). Es inofensivo: el
     * resultado solo se usa como usuario autenticado; el aislamiento de
     * datos de negocio lo aporta TenantFilter + RequireTenantListener.
     *
     * Verificado por test: AppUserRepositoryInterfaceTest (reflexión).
     */
    public function findById(int $id): ?AppUser;

    /**
     * Todos los usuarios del sistema, para la visión global del
     * super_admin (ADR-0016; comando app:user:list).
     *
     * Lista identidades de aplicación — no datos de negocio — y su
     * único consumidor es el panel de administración (CLI en la Fase
     * 3.6, vistas /admin en una posterior). Los lookups de negocio
     * siguen amarrados a su tenant vía TenantFilter.
     *
     * @return AppUser[]
     */
    public function findAll(): array;
}
