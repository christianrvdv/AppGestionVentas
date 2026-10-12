# Task List: Fase 6.1 — Panel admin básico

## Phase 1: Dashboard admin
- [x] T1: Tests RED (`AdminDashboardTest`, `AdminAccessTest`)
- [x] T2: Conteos en repos + interfaces (Tenant/AppUser/TenantMembership/Investment/Sale)
- [x] T3: `AdminMetricsService` (globales y por tenant)
- [x] T4: `AdminController::index` con métricas + template `admin/index`

Checkpoint: `php bin/phpunit --filter=Admin` verde

## Phase 2: Listado y detalle de tenants
- [x] T5: Test RED `AdminTenantIndexTest` (paginado + filtros activo/búsqueda)
- [x] T6: `TenantRepository::createPaginatedQueryBuilder` + `findAll` + `AdminTenantController::index`
- [x] T7: Test RED `AdminTenantShowTest` (usuarios, métricas, toggle, desactivado no seleccionable)
- [x] T8: `AdminTenantController::show`/`toggle-active` + templates

Checkpoint: `php bin/phpunit --filter=Admin` verde

## Phase 3: Listado y detalle de usuarios
- [x] T9: Test RED `AdminUserIndexTest` (membresías + filtros email/tenant/rol)
- [x] T10: `AppUserRepository::createPaginatedQueryBuilder` (email/tenant/rol global) + `AdminUserController::index`
- [x] T11: Test RED `AdminUserShowTest` (membresías, desactivar → sin login, reset password)
- [x] T12: `AdminUserController::show`/`toggle-active`/`reset-password` + templates

Checkpoint: `php bin/phpunit --filter=Admin` verde

## Phase 4: Reset con forzado de cambio
- [x] T13: Migración `must_change_password` + propiedad en `AppUser`
- [x] T14: Test RED `AdminPasswordChangeTest` (reset → login forzado → cambio → libre)
- [x] T15: `PasswordChangeFormType` + `PasswordChangeController` + `EnforcePasswordChangeListener` + template

## Phase 5: Cross-tenant
- [x] T16: Test `AdminCrossTenantTest` (super_admin sin datos ajenos en /inversiones; métricas del detalle aisladas)

## Phase 6: Nav y cierre
- [x] T17: Link "Admin" en `base.html.twig` (solo super_admin)
- [x] T18: `composer ci` + `php bin/phpunit --order-by=random` verdes y commit único
