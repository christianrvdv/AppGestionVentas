# Task List: Fase 6.2 — Impersonation y gestión de membresías

Plan: `tasks/plan-fase62.md` (el de la 6.1 sigue en `tasks/plan.md`).

## Phase 1: Impersonation core

- [x] T1: Tests RED (`AdminImpersonationTest`: entra/sale + auditoría)
- [x] T2: `ImpersonationSession` (claims de sesión) + limpieza en `TenantSessionListener`
- [x] T3: Entidad `ImpersonationLog` + migración (`Version20261014173417`)
- [x] T4: `ImpersonationService::start/stop`
- [x] T5: `TenantFilterListener` honra la marca (filtro ACTIVO con el tenant impersonado)

Checkpoint: `php bin/phpunit --filter=Impersonation` verde

## Phase 2: Impersonation UI

- [x] T6: Rutas `POST /admin/tenants/{id}/impersonate` y `POST /admin/impersonate/stop`
- [x] T7: Banner en `base.html.twig` + global `impersonation` en twig.yaml
- [x] T8: Botón "Entrar como este tenant" en `admin/tenant/show`

Checkpoint: `php bin/phpunit --filter=Impersonation` verde

## Phase 3: Membresías core + UI

- [x] T9: Tests RED (`AdminMembershipManagementTest`: alta, duplicado, rol, baja, invariantes)
- [x] T10: `MembershipViolationException` + `MembershipManagementService`
- [x] T11: Rutas `add-membership` / `change-role` / `remove` + `AdminMembershipController`
- [x] T12: Sección "Membresías" editable en `admin/user/show`

Checkpoint: `php bin/phpunit --filter=Membership` verde

## Phase 4: Acceso, ADR y cierre

- [x] T13: `AdminAccessTest::adminRoutes()` con las 5 rutas nuevas (403 + anónimo)
- [x] T14: ADR-0019 (tenant vs usuario, auditoría, límites)
- [ ] T15: `composer ci` + `php bin/phpunit --order-by=random`
- [ ] T16: Commit `feat(admin): impersonation y gestión de membresías (Fase 6.2)`
