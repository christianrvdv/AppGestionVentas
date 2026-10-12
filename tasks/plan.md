# Implementation Plan: Fase 6.1 — Panel admin básico

## Objetivo

UI para que `ROLE_SUPER_ADMIN` vea y gestione globalmente tenants y
usuarios: dashboard con contadores, listados paginados/filtrables,
detalles con métricas, activar/desactivar y reset de password.
(Fuera de alcance: impersonation, alta/baja de tenants, edición de
membresías, reset por email, métricas agregadas complejas.)

## Module Dependency Graph

```
admin-metrics (contadores; reutiliza repos existentes)
    ├─
admin-dashboard (usa admin-metrics)
    ├─
admin-tenants (listado/detalle/toggle; usa admin-metrics + Investment/Sale repo con tenantId explícito)
    ├─
admin-users (listado/detalle/toggle/reset; usa memberships)
    └─
password-reset (migración must_change_password + listener de forzado + /cambiar-password)
```

Build order: admin-metrics → dashboard → tenants → users → password-reset → cross-tenant/nav.

## Decisiones de diseño

1. **Rutas** (todas bajo `^/admin` → `ROLE_SUPER_ADMIN` en security.yaml,
   blanqueadas en RequireTenantListener sin tenant activo):

   | Método | Ruta | Nombre |
   |---|---|---|
   | GET | `/admin` | `app_admin_index` |
   | GET | `/admin/tenants` | `app_admin_tenant_index` |
   | GET | `/admin/tenants/{id}` | `app_admin_tenant_show` |
   | POST | `/admin/tenants/{id}/toggle-active` | `app_admin_tenant_toggle_active` |
   | GET | `/admin/users` | `app_admin_user_index` |
   | GET | `/admin/users/{id}` | `app_admin_user_show` |
   | POST | `/admin/users/{id}/toggle-active` | `app_admin_user_toggle_active` |
   | POST | `/admin/users/{id}/reset-password` | `app_admin_user_reset_password` |
   | GET/POST | `/cambiar-password` | `app_password_change` |

   Controllers nuevos en `src/Controller/Admin/`; el `AdminController`
   existente queda solo con el dashboard (h1 "Administración", que ya
   afirma `SuperAdminFlowTest`).

2. **Cross-tenant safety**: el super_admin corre con `TenantFilter`
   deshabilitado, así que TODA query de detalle lleva el `tenantId`
   explícito en la cláusula: reutilizar
   `InvestmentRepository::createPaginatedQueryBuilder($tenantId, null)` y
   `SaleRepository` con `tenantId` explícito. `/inversiones` exige
   `active_tenant_id` (403 para super_admin) — comportamiento existente,
   ahora con test.

3. **Contadores**: los totales son conteos de filas crudos (incluyen
   inversiones canceladas y ventas anuladas = histórico); el **monto
   total vendido excluye anuladas** (coherente con el default de
   `SaleRepository`). Ambas decisiones se documentan en el reporte.

4. **Filtro de rol global en /admin/users**: DQL no tiene `CAST` y
   `jsonb LIKE` no existe en PostgreSQL, así que el rol se resuelve con
   una pre-lookup por DBAL (`SELECT id FROM app_user WHERE roles::text
   LIKE '%"ROLE_X"%'`) y en la query principal se filtra `u.id IN (:ids)`.
   Postgres-only es coherente con ADR-0011. El valor del rol se
   validación contra allow-list en el controller antes de tocar la query.

5. **Listado de usuarios y membresías**: fetch-join
   (`leftJoin('u.memberships')->addSelect('m')`); KnpPaginator usa
   `Doctrine\ORM\Tools\Pagination\Paginator` con `CountWalker` (COUNT
   DISTINCT + fetch join collection), así que el total no se infla.

6. **Reset de password (decisión del usuario: opción A)**: genera
   password temporal, la muestra UNA vez en un flash al volver al
   detalle, y fuerza el cambio en el próximo login:
   - migración `app_user.must_change_password` (bool, default false);
   - `EnforcePasswordChangeListener` (kernel.request, prioridad 7,
     entre firewall=8 y RequireTenantListener=6) redirige a
     `/cambiar-password` mientras la bandera esté puesta;
   - `PasswordChangeController` + form (RepeatedType, 8+ caracteres) la
     limpia. `/cambiar-password` se añade a los prefijos permitidos de
     RequireTenantListener.

7. **CSRF**: tokens planos por acción (`_csrf_token` con id de token por
   acción, patrón `select_tenant`/`submit` ya usado). Los toggles
   destructivos llevan `onsubmit="return confirm(...)"` + advertencia
   visible (patrón de `investment/show`).

8. **Nav**: link "Admin" en `base.html.twig` solo para
   `app.user.superAdmin`, resaltado con el prefijo `app_admin_`.

## Estructura de archivos

```
src/Controller/Admin/AdminTenantController.php     (index/show/toggle)
src/Controller/Admin/AdminUserController.php       (index/show/toggle/reset)
src/Controller/PasswordChangeController.php        (/cambiar-password)
src/Controller/AdminController.php                 (dashboard con métricas)
src/Service/Admin/AdminMetricsService.php          (contadores globales y por tenant)
src/EventListener/EnforcePasswordChangeListener.php
src/Form/PasswordChangeFormType.php
src/Entity/AppUser.php                             (+ mustChangePassword)
migrations/Version*.php                             (+ must_change_password)
src/Repository/{Tenant,AppUser,TenantMembership,Investment,Sale}Repository.php (+QB/conteos)
src/Repository/Contract/{...}Interface.php          (mismos métodos)
templates/admin/index.html.twig                     (dashboard)
templates/admin/tenant/{index,show}.html.twig
templates/admin/user/{index,show}.html.twig
templates/security/change_password.html.twig
templates/base.html.twig                           (nav Admin)
tests/Functional/Admin/*.php                        (9 escenarios + password change)
```

## Testing Strategy

- Framework: PHPUnit 13 + `FunctionalTestCase` (client de kernel
  compartido, BD PostgreSQL de test, aislamiento DAMA).
- Comandos:
  - Foco: `php bin/phpunit --filter=AdminDashboardTest`
  - Total: `php bin/phpunit --order-by=random`
  - CI: `composer ci` (lint:container + lint:twig + lint:yaml + phpstan + tests)
- TDD: cada fase escribe su test (RED) antes que su código (GREEN).

### Matriz de escenarios (9 del alcance + reset)

| # | Escenario | Test |
|---|---|---|
| 1 | Dashboard con contadores correctos | `Admin/AdminDashboardTest` |
| 2 | Listado de tenants paginado y filtrable | `Admin/AdminTenantIndexTest` |
| 3 | Detalle de tenant: usuarios + métricas | `Admin/AdminTenantShowTest` |
| 4 | Listado de usuarios con membresías | `Admin/AdminUserIndexTest` |
| 5 | Detalle de usuario con membresías | `Admin/AdminUserShowTest` |
| 6 | Usuario desactivado no puede loguear | `Admin/AdminUserShowTest` |
| 7 | Tenant desactivado no es seleccionable | `Admin/AdminTenantShowTest` |
| 8 | Usuario normal → 403 en todas las rutas | `Admin/AdminAccessTest` |
| 9 | Cross-tenant en /inversiones | `Admin/AdminCrossTenantTest` |
| + | Reset + forzado de cambio | `Admin/PasswordChangeTest` |

## Boundaries

- Always: query de detalle con `tenantId` explícito; tests en verde con
  `--order-by=random`; `composer ci` antes del commit.
- Ask first: cambios de esquema (migración), nuevas dependencias, roles
  nuevos.
- Never: impersonation, alta/baja de tenants desde el panel, reset por
  email (sin mailer), editar membresías.

## Success Criteria

- `/admin`, `/admin/tenants[/{id}]`, `/admin/users[/{id}]` operativos.
- Los 9 escenarios + reset cubiertos por tests.
- `php bin/phpunit --order-by=random` y `composer ci` en verde.
- Un único commit: `feat(admin): panel admin básico (tenants, usuarios, dashboard) (Fase 6.1)`
