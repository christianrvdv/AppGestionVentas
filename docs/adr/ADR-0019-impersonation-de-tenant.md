# ADR-0019: Impersonation de tenant y gestión de membresías desde /admin

- **Estado:** Aceptado (Fase 6.2).
- **Fecha:** 2026-10-14.
- **Contexto:** Fase 6.2 del panel de administración. Dos necesidades de
  soporte que el panel de la Fase 6.1 no cubre:
  1. **Ver un negocio como lo ve su gente.** Un super_admin puede listar
     todos los tenants y sus métricas agregadas, pero no puede recorrer
     la app (dashboard, productos, ventas) como la usa un cliente. Para
     diagnosticar "me sale vacío", "no veo ese producto" hace falta
     entrar al negocio, no solo mirar su ficha.
  2. **Mover usuarios entre negocios.** Alta, cambio de rol y baja de
     membresías hoy solo se hacen desde la ficha del negocio; el panel no
     puede agregar un usuario existente a otro negocio ni quitarlo de uno.

## Problema

### Impersonation: las opciones y por qué no

| Opción | Por qué no |
| --- | --- |
| Impersonar al **usuario** (cambiar el token) | El super_admin dejaría de ser super_admin dentro de la sesión: perdería el panel, y cada "entrar/salir" reescribiría identidad, roles y auditoría. Además la app distinguiría mal quién hizo cada acción. |
| Cambiar solo `active_tenant_id` **sin marca** | El TenantFilter se habilitaría, pero nadie (ni el banner, ni `TenantFilterListener`) sabría que el cambio fue deliberado: el super_admin quedaría "atrapado" en el negocio sin forma de distinguirlo de una membresía real, y no habría auditoría de quién entró. |
| Duplicar toda la app bajo `/soporte/...` | Duplicaría rutas, controllers y plantillas para replicar lo que el filtro ya sabe hacer: aislar por tenant. |

Y un punto de fondo: el impersonation **no es de negocio solo por
convención**, es que el aislamiento de esta app vive en el SQLFilter de
tenant (ADR-0014). Ver un negocio = correr el filtro con ese tenant. Eso
es exactamente lo que hace que la opción correcta sea cambiar el tenant
del filtro, no el usuario.

### Membresías: qué invariantes rompe un POST malicioso

Un formulario "agregar a negocio / cambiar rol / quitar" sobre
`tenant_membership` puede dejar el sistema en estados inviables si la
validación vive solo en la vista:

| Invariante | Cómo se rompe |
| --- | --- |
| Un usuario no pertenece dos veces al mismo negocio | Dos POST seguidos del mismo formulario (doble click, reintento). |
| Un negocio no queda sin usuarios | Quitar la única membresía: el tenant queda vacío e inoperable. |
| Un usuario no queda sin acceso | Quitar la última membresía del usuario: queda logueado sin nada que ver (y en `/onboarding` si se reloguea). |
| Un negocio no queda sin administradores | Degradar o dar de baja al único `ROLE_ADMIN`: nadie podría gestionar ese negocio. |

## Decisión

### 1. Impersonation de tenant con marca de sesión

`ImpersonationSession` (servidor-solo, como `ActiveTenantSession`,
ADR-0015) guarda la marca: tenant impersonado, cuándo, qué super_admin,
la fila de auditoría a cerrar y el tenant anterior. El token y el usuario
**no se tocan**: el super_admin sigue siendo super_admin.

`TenantFilterListener` cambia una sola línea de contrato: el bypass de
super_admin (ADR-0016) solo aplica cuando NO hay impersonation activo. Con
la marca, el super_admin corre el filtro normal con el tenant impersonado
— y por eso ve exactamente los datos de ese negocio, ni más ni menos.

### 2. Auditoría en tabla propia `impersonation_log`

Una fila por entrada (`started_at`) con `ended_at` al salir. Se audita
quién, en qué negocio y cuándo; no se auditan las acciones dentro del
impersonation (el usuario autenticado sigue siendo el super_admin, y las
acciones administrativas ya quedan registradas por el resto del panel).
Las FK son `RESTRICT`: borrar un negocio o un usuario con auditoría de
soporte debe fallar, no borrar la evidencia.

El cierre de la fila se busca con `TenantFilterGuard::withoutTenantFilter`:
durante un impersonation el filtro está habilitado y una operación de
registro no debe depender de qué filtro esté activo.

### 3. La marca se limpia en login y logout

`TenantSessionListener` borra los claims de impersonation en
`LoginSuccessEvent` y `LogoutEvent`, igual que `active_tenant_id`:
`migrate()` conserva atributos de la sesión, así que una cuenta nueva del
mismo navegador no debe heredar el impersonation de la anterior. Un
logout **dentro** de un impersonation deja la fila abierta
(`ended_at` NULL): es un corte de sesión, no una salida, y la marca de
"nunca salió limpio" es justamente lo que se quiere registrar.

### 4. Membresías: invariantes en el servidor, rol ≠ rol global

`MembershipManagementService` valida en servidor, en este orden (lo más
grueso primero): duplicado → última membresía del negocio → última del
usuario → último administrador (tanto al quitar como al degradar). Los
mensajes de cada caso son el contrato de la UI.

El rol que se gestiona es `TenantMembership::ROLE_ADMIN/ROLE_USER`, un
rol **de membresía** que NO se traduce a roles de Symfony (ADR-0016): el
alta, el cambio y la baja nunca tocan `app_user.roles`. Es lo que hace
segura la operación desde el panel: cambiar de negocio no cambia quién es
el usuario.

### 5. UI: banner en todas las páginas y formularios con CSRF por acción

- Banner `#banner-impersonation` en `base.html.twig` (visible en TODAS
  las páginas) con la salida `#form-impersonate-stop`: estar impersonando
  sin un recordatorio permanente es un riesgo de operar equivocado.
- Botón "Ver como este solo mientras NO hay otro activo" en la ficha del
  negocio; la salida siempre devuelve a `/admin`.
- Ficha de usuario: formulario de alta (negocio + rol) y, por membresía,
  cambio de rol y quitar. Cada acción tiene su propio token CSRF
  (`admin_impersonate`, `admin_impersonate_stop`,
  `admin_user_add_membership`, `admin_membership_change_role`,
  `admin_membership_remove`); un fallo de CSRF es un flash de error, no
  un 500.

## Consecuencias

- **Durante un impersonation, `/admin` también corre con el filtro
  activo** (tenant impersonado). Es aceptado: el impersonation significa
  "ver como ese negocio", y el panel global es una vista más de la app.
  El test cubre que `/admin` sigue accesible; no promete que el listado
  de tenants muestre los otros negocios mientras se impersona.
- **La salida restaura el tenant anterior** (normalmente ninguno), no
  "el último tenant del usuario": el super_admin no navega por
  membresías.
- **El rol de membresía no se sincroniza con `app_user.roles`** ni con
  el token de sesión: un cambio de rol no surte efecto hasta que el
  usuario reloguee o elije negocio de nuevo. Es intencional (ADR-0016)
  y está cubierto por `testSuperAdminKeepsGlobalRoleAfterMembershipChanges`.
- **Las invariantes de membresía no se pueden relajar desde la UI**: un
  POST directo recibe el mismo rechazo que el formulario.
