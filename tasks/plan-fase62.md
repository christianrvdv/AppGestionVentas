# Implementation Plan: Fase 6.2 — Impersonation y gestión de membresías

## Objetivo

Dos capacidades del panel `ROLE_SUPER_ADMIN` que la Fase 6.1 dejó
explícitamente fuera de alcance:

1. **Impersonation de tenant**: el super_admin entra a un negocio
   concreto para diagnosticar o dar soporte, con banner visible y
   auditoría persistida.
2. **Gestión de membresías**: agregar un usuario a un tenant, cambiar
   su rol dentro del tenant y quitarlo, con las invariantes del dominio
   (última membresía, último administrador) validadas en servidor.

Fuera de alcance: impersonation de usuario, mailer/notificaciones,
Chart.js, deploy.

## Decisiones (confirmadas con el humano)

| Decisión | Elección |
|---|---|
| Alcance del impersonation | **De tenant**: solo cambia el tenant activo; el super_admin conserva su identidad, su sesión y su rol. No se ve como un usuario concreto. |
| Auditoría | **Tabla Doctrine propia** (`impersonation_log`), consultable y durable, con inicio y fin de cada sesión. |

## Module Dependency Graph

```
impersonation-core (ImpersonationSession + ImpersonationLog + servicio + TenantFilterListener)
    ↓
impersonation-ui (rutas POST, banner en base.html.twig, botón en la ficha del tenant)
    ↓
impersonation-tests

memberships-core (MembershipManagementService + invariantes)
    ↓
memberships-ui (rutas POST + sección "Membresías" en la ficha del usuario)
    ↓
memberships-tests
```

Build order: `impersonation-core → impersonation-ui → memberships-core →
memberships-ui → tests → ADR-0019 → ci`.

Los dos módulos son independientes entre sí; el orden solo respeta
"núcleo antes que UI, UI antes que tests".

## 1. Impersonation

### 1.1 Mecanismo

El impersonation **NO** cambia el usuario autenticado ni el token de
seguridad: solo escribe en sesión el tenant activo y una marca de
"estoy impersonando". Consecuencias, que son el diseño:

- `access_control ^/admin → ROLE_SUPER_ADMIN` sigue cumpliéndose (el
  rol es del usuario y no cambia).
- `RequireTenantListener` no cambia (el bypass de super_admin ya
  existía; además ahora hay tenant activo).
- **`TenantFilterListener` sí cambia**: hoy deshabilita el SQLFilter
  para cualquier super_admin. Con la marca activa debe tomar el camino
  NORMAL (habilitar el filtro con el tenant impersonado y poblar
  `TenantContext`), que es lo que hace que "vea solo datos del tenant".
  Sin la marca, el comportamiento ADR-0016 queda idéntico.

### 1.2 Claims de sesión (`App\Security\ImpersonationSession`)

| Claim | Tipo | Significado |
|---|---|---|
| `impersonating_tenant_id` | `int` | tenant elegido |
| `impersonating_started_at` | `int` | unix timestamp del inicio |
| `impersonating_by_user_id` | `int` | id del super_admin que entra |
| `impersonating_log_id` | `int` | fila de auditoría a cerrar al salir |
| `impersonating_previous_tenant_id` | `int\|null` | tenant que tenía antes (para restaurarlo al salir) |

Los tres primeros son los pedidos por el alcance; los dos últimos son
implementación (sin `log_id` no se puede cerrar la fila exacta; sin el
anterior, salir pisaría un negocio que el super_admin tenía elegido).
Misma forma que `ActiveTenantSession`: solo ints positivos, el valor
nunca viene del request.

Limpieza obligatoria además de "stop": `TenantSessionListener` limpia
estos claims en `LoginSuccessEvent` y `LogoutEvent` (misma razón que el
`active_tenant_id`: `migrate()` conserva atributos y una cuenta nueva
del mismo navegador no debe heredar el impersonation).

### 1.3 Rutas

| Método | Ruta | Nombre | Notas |
|---|---|---|---|
| POST | `/admin/tenants/{id}/impersonate` | `app_admin_tenant_impersonate` | CSRF `admin_impersonate` |
| POST | `/admin/impersonate/stop` | `app_admin_impersonate_stop` | CSRF `admin_impersonate_stop` |

Ambas bajo `^/admin` → `ROLE_SUPER_ADMIN` por `access_control`, y
blanqueadas en `RequireTenantListener` por el prefijo `/admin`.

Comportamiento:

- **start**: 404 si el tenant no existe; flash de error si ya hay un
  impersonation activo (cada `start` debe tener su `stop` para que la
  auditoría cierre); escribe claims, pisa `active_tenant_id` (guardando
  el anterior), persiste `ImpersonationLog` y redirige a `/` (donde ya
  se ven los datos del negocio).
- **stop**: si no hay impersonation activo, vuelve a `/admin`; si lo
  hay, cierra la fila de auditoría (`ended_at`), limpia los claims,
  restaura el tenant anterior (o lo limpia) y redirige a `/admin`.

### 1.4 Auditoría

Entidad `App\Entity\ImpersonationLog`, tabla `impersonation_log`:

| Columna | Tipo | Restricción |
|---|---|---|
| `id` | int identity | PK |
| `super_admin_id` | int | FK → `app_user`, RESTRICT |
| `tenant_id` | int | FK → `tenant`, RESTRICT |
| `started_at` | datetime_immutable | NOT NULL |
| `ended_at` | datetime_immutable | NULL (NULL = sesión aún abierta) |

Índices en `tenant_id` y `super_admin_id`. Migración Doctrine
(`make:migration`), nunca `schema:update`.

Detalle de mapeo: la asociación se llama `tenant`, así que el
`TenantFilter` la cubre. Eso es correcto y no rompe el cierre: al salir
el filtro está habilitado CON ese mismo tenant, así que la fila pasa el
filtro; además la búsqueda de la fila corre con
`TenantFilterGuard::withoutTenantFilter()` para que la auditoría nunca
dependa del filtro activo.

### 1.5 Banner

En `templates/base.html.twig`, justo después del `</header>` (dentro de
`{% if app.user %}`), visible en TODAS las páginas mientras la marca
esté activa:

```
Viendo como [Tenant].   [Salir]
```

Twig global nuevo `impersonation` → servicio `ImpersonationSession`
(junto a `active_tenant` en `config/packages/twig.yaml`); el nombre del
negocio sale de `active_tenant`, que ya está poblado con el tenant
impersonado.

## 2. Gestión de membresías

### 2.1 Rutas (todas `^/admin` → ROLE_SUPER_ADMIN)

| Método | Ruta | Nombre | Controller |
|---|---|---|---|
| POST | `/admin/users/{id}/memberships` | `app_admin_user_add_membership` | `AdminUserController` |
| POST | `/admin/memberships/{id}/change-role` | `app_admin_membership_change_role` | `AdminMembershipController` (nuevo) |
| POST | `/admin/memberships/{id}/remove` | `app_admin_membership_remove` | `AdminMembershipController` |

CSRF plano por acción (`admin_user_add_membership`,
`admin_membership_change_role`, `admin_membership_remove`), mismo
patrón que los toggles de la 6.1.

### 2.2 Servicio `App\Service\Admin\MembershipManagementService`

Único punto de entrada de las tres operaciones; lanza
`App\Exception\Domain\MembershipViolationException` (hereda de
`DomainException`) y el controller la traduce en flash de error.

Invariantes (validadas en servidor, nunca solo en el formulario):

1. Rol permitido: allow-list `[ROLE_ADMIN, ROLE_USER]` de
   `TenantMembership`. Nada de roles globales.
2. **Duplicado**: si el usuario ya tiene membresía en ese tenant →
   error (además de la unique `uniq_membership_user_tenant` en BD).
3. **Última membresía del tenant**: no se puede quitar la única fila
   del tenant (dejaría el negocio huérfano).
4. **Última membresía del usuario**: no se puede quitar la única fila
   del usuario (se quedaría sin acceso).
5. **Últimen administrador**: no se puede quitar un miembro con
   `ROLE_ADMIN` si es el único admin del tenant, ni bajar a `ROLE_USER`
   al único admin del tenant.

Orden de los chequeos al quitar: (3) tenant → (4) usuario → (5) admin,
para que el mensaje diga la causa más grave.

Las cuentas se calculan con `findByTenant()` / `findByUser()` del
puerto existente (ambos ya corren con el filtro deshabilitado): no hace
falta tocar el repositorio ni su puerto, que está blindado por tests de
reflexión (ADR-0015).

El servicio **nunca escribe `AppUser.roles`**: el rol global no se toca
en esta fase, y hay un test que lo afirma sobre el propio super_admin.

### 2.3 UI

- `templates/admin/tenant/show.html.twig`: botón **"Entrar como este
  tenant"** (form POST + CSRF).
- `templates/admin/user/show.html.twig`: sección **"Membresías"**
  (ya existente como `#titulo-membresias`, renombrar el h2 de
  "Negocios") con:
  - formulario "Agregar a un tenant": select de tenants (excluyendo los
    donde ya es miembro) + select de rol + botón;
  - por fila: select de rol + botón "Cambiar rol", y botón "Quitar del
    tenant" con `confirm()` previo.
- `templates/base.html.twig`: banner de impersonation.

## 3. Tests (9 escenarios del alcance + invariantes)

Todos en `tests/Functional/Admin/`, sobre `FunctionalTestCase` (DAMA),
con `em->clear()` antes de leer estado de BD (ADR-0017).

| # | Test | Cubre |
|---|---|---|
| 1 | `AdminImpersonationTest::testSuperAdminEntersAsTenantAndStops` | entra → banner + solo datos del tenant → sale → vuelve al panel |
| 2 | `AdminImpersonationTest::testImpersonationIsAudited` | fila creada al entrar, `ended_at` al salir |
| 3 | `AdminMembershipManagementTest::testAddMembershipToExistingTenant` | alta |
| 4 | `AdminMembershipManagementTest::testAddDuplicateMembershipFails` | duplicado |
| 5 | `AdminMembershipManagementTest::testChangeMembershipRole` | cambio de rol |
| 6 | `AdminMembershipManagementTest::testRemoveMembership` | baja |
| 7 | `AdminMembershipManagementTest::testCannotRemoveLastMembershipOfTenant` | invariante tenant |
| 8 | `AdminMembershipManagementTest::testCannotRemoveLastMembershipOfUser` | invariante usuario |
| 9 | `AdminAccessTest` (rutas nuevas agregadas a `adminRoutes()`) | 403 y 401/redirect en TODAS las rutas admin |

Extra (validaciones del alcance 3): `testCannotDemoteLastAdmin` y
`testSuperAdminKeepsGlobalRoleAfterMembershipChanges`.

## 4. ADR-0019

`docs/adr/ADR-0019-impersonation-de-tenant.md`, formato de ADR-0017/0018:
impersonation de tenant vs. de usuario, modelo de auditoría, y el
límite explícito de lo que NO ve el super_admin (no cambia de usuario,
no hereda roles, no salta el filtro de tenant).

## Criterios de éxito (DoD)

- [ ] Impersonation funcional con banner y auditoría.
- [ ] Gestión de membresías funcional con las 5 invariantes.
- [ ] Tests cubren los 9 escenarios (+2 extra).
- [ ] `composer ci` verde (lint + phpstan 6/5 + suite).
- [ ] `php bin/phpunit --order-by=random` verde.
- [ ] Un commit: `feat(admin): impersonation y gestión de membresías (Fase 6.2)`.
