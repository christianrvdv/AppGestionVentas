# Plan: Fase 1 UI — Seguridad, layout base, dashboard y fixtures

Ver spec: `docs/spec/SPEC-web-foundation-fase1.md`. Tareas registradas en
`tasks/todo.md` (checklist del repo; no hay tracker externo).

## Arquitectura / decisiones clave
- Login multi-tenant con `form_login` estándar: `AppUserProvider` lee
  `tenant_slug` del request vía `RequestStack` (patrón multi-tenant con
  form_login; el provider no recibe el request de otra forma). `refreshUser`
  recarga por id + tenantId (no necesita el request).
- `UserChecker` propio rechaza usuarios con `isActive() === false`.
- TenantContext/TenantFilterListener ya existentes se reutilutan tal cual
  (el listener puebla el contexto desde el AppUser autenticado).
- Test de humo: SQLite in-memory + `disableReboot()` para conservar la BD
  entre requests de un mismo test; esquema recreado por test.
- Tailwind v4 local vía symfonycasts/tailwind-bundle (NO CDN).

## Orden y dependencias

### Fase A — Cimientos
1. ADR-0009 (tenant por slug) — sin dependencias.
2. Instalar `doctrine/doctrine-fixtures-bundle` — sin dependencias.

### Fase B — Datos de prueba
3. Fixtures (Tenant → AppUser → AppSetting → Product → Customer → UsdRate).
   Verify: `doctrine:fixtures:load` sobre BD vacía.

### Fase C — Seguridad
4. `AppUserProvider` + `AppUserChecker`.
5. `security.yaml`: provider, form_login, logout, remember_me, access_control.
6. `SecurityController` + `templates/security/login.html.twig`.

### Fase D — Humo (TDD: tests escritos junto al paso, verdes antes de avanzar)
7. Suite Functional en phpunit.dist.xml + smoke tests de auth (rojo → verde
   con los pasos 4-6).

### Fase E — Layout y dashboard
8. `base.html.twig` (skip-link, nav, flashes, bloques, Turbo).
9. `DashboardController` + `dashboard/index.html.twig`.
10. Smoke test del dashboard autenticado (extiende suite F).

### Fase F — Verificación y cierre
11. Accesibilidad login/dashboard (labels, focus, contraste, autocomplete).
12. lint:twig, lint:container, schema:validate, suite completa.
13. Revisión seguridad + code review; commit único.

## Riesgos
| Riesgo | Impacto | Mitigación |
|---|---|---|
| Kernel reboot en tests borra SQLite in-memory | Alto | `disableReboot()` + recrear esquema por test |
| Filtro tenant con parámetro stale entre requests | Medio | En login el filtro se habilita tras autenticar con tenant real; verificar en test |
| Purge de BD dev al cargar fixtures | Medio | Inspeccionar contenido antes; BD dev sin datos de valor |
| Tailwind v4 no escanea templates/ | Bajo | Source detection automática de v4; verificar CSS compilado en test manual |

## Checkpoints
- Después de Fase B: fixtures OK sobre BD vacía.
- Después de Fase D: smoke de auth verde con login real.
- Después de Fase E: dashboard renderiza; suite completa verde.
- Antes del commit: DoD completo.
