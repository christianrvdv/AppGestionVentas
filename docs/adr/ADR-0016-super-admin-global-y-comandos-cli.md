# ADR-0016: Rol global super_admin y comandos CLI de usuarios

- **Estado:** Aceptado (Fase 3.6).
- **Fecha:** 2026-10-10 (Fase 3.6)
- **Contexto:** Fase 3.6 — hace falta administrar el sistema (crear
  usuarios, resetear contraseñas, ver quién existe) sin pertenecer a un
  negocio concreto y sin una UI de administración todavía.

## Problema

Con ADR-0015, **todos** los roles viven en `TenantMembership`: `ROLE_ADMIN`
y `ROLE_USER` son roles *de negocio*. Eso deja tres vacíos:

1. **No existe una cuenta que administre el sistema.** "Ver todos los
   usuarios" o "resetear la contraseña de alguien" exigirían un rol que
   fuera de un tenant, y `RequireTenantListener` + `TenantFilter`
   asumen que toda sesión tiene (o elige) un negocio.
2. **No hay alta de usuarios operativa.** No hay UI de registro ni de
   invitaciones; sin un comando, la única vía sería escribir a mano en
   la BD.
3. **La ruta `^/admin` quedó protegida en el access_control sin destino.**
   Un rol que no existe no se puede pedir, y una ruta que no existe
   responde 404 antes de evaluar permisos.

## Decisión

Crear un rol **global** `ROLE_SUPER_ADMIN` guardado en `AppUser::roles`
(no en membresía) y tres comandos CLI como único mecanismo de
administración de usuarios:

```
php bin/console app:user:create --super-admin --email=... 
php bin/console app:user:create --email=... [--password=...] [--tenant=slug] [--role=ROLE_ADMIN]
php bin/console app:user:change-password --email=...
php bin/console app:user:list [--tenant=slug]
```

## Implementación

### Identidad y detección

- `AppUser::ROLE_SUPER_ADMIN` es una constante de la entidad;
  `AppUser::isSuperAdmin()` mira `$this->roles` **guardados**, nunca
  `getRoles()` (que añade `ROLE_USER` por convención y no distingue a
  nadie).
- Un super_admin **no tiene membresías** por diseño: `app:user:create
  --super-admin` ignora `--tenant`/`--role` con un `note`. La lista de
  usuarios lo pinta con "—" en Negocios.

### Aislamiento relajado, no eliminado

El aislamiento por tenant no se apaga para todos, se *deshabilita para
este rol* en los tres puntos que lo administran:

| Punto | Comportamiento con super_admin |
| --- | --- |
| `TenantFilterListener` | Limpia `TenantContext` y **no habilita** `TenantFilter` (sin tenant al que filtrar). |
| `RequireTenantListener` | `return` inmediato: nunca se le exige tenant activo. Whitelist además del prefijo `/admin`. |
| `TenantSessionListener` | `clear()` de la sesión y `return` **sin** redirigir: el destino sigue siendo el `default_target_path` (`/`), no `/onboarding` ni `/select-tenant`. |

Orden de prioridades en `kernel.request` (Router 32 → firewall 8 →
RequireTenant 6 → TenantFilter 5) implica que **con la ruta `/admin`
creada**, un rol que no es super_admin choca contra `access_control` (403)
*antes* de llegar al bypass del listener: el bypass no es una puerta de
entrada, solo evita el loop de redirecciones del propio super_admin.

### Dashboard sin negocio

`DashboardController` acepta el caso `tenantId === null` **solo** si el
usuario es super_admin (si no, 403 como antes) y renderiza con
`emptyTotals()` — el mismo shape que `sumTotalsByTenant()` (13 claves
`'0.00'`), `usd_rate: null` e `active_investments: []`. La vista no se
bifurca: rinde igual con y sin negocio.

### `/admin`

`AdminController` (placeholder, panel completo en fase posterior) es la
única ruta que opera sin tenant. En `security.yaml`:

```yaml
- { path: ^/admin, roles: ROLE_SUPER_ADMIN }   # no ROLE_ADMIN: es rol GLOBAL
- { path: ^/,      roles: ROLE_USER }
```

`ROLE_ADMIN` de membresía **no** entra: lo cubre
`SuperAdminFlowTest::testAdminPanelRejectsRegularMemberEvenWithHighestBusinessRole`.

### Comandos CLI

- `AppUserRepositoryInterface::findAll()` es el único lookup agregado:
  lista **identidades** de aplicación, no datos de negocio, y tampoco
  recibe `$tenantId` (el invariante ADR-0015 de "sin tenant en la
  firma" se mantiene; el test de reflexión lo actualiza y exige el
  docblock `ADR-0016`).
- `TenantMembershipRepository::findByTenant(Tenant)` existe para
  `app:user:list --tenant`: corre con `TenantFilterGuard::withoutTenantFilter`
  porque el panel puede mirar un negocio que no es el de la sesión.
- Passwords: hasher real de la aplicación (`UserPasswordHasherInterface`),
  mínimo 8 caracteres, diálogo oculto con confirmación cuando no se pasa
  `--password`. El email siempre es opción en `change-password`
  (scripteable) y se dialoga en `create` cuando se omite.
- En CLI no corren listeners de `kernel.request`, así que `TenantFilter`
  no está habilitado: los repos funcionan sin guard en este camino.

## Alternativas descartadas

| Alternativa | Por qué no |
| --- | --- |
| Rol `ROLE_ADMIN` global en `AppUser` | Colisiona con el `ROLE_ADMIN` de membresía: `isGranted('ROLE_ADMIN')` dejaría de distinguir "admin de su negocio" de "admin del sistema". |
| UI de administración desde el inicio | Fuera de alcance de la fase; sin UI no hay forma de crear la primera cuenta. |
| Seed de super_admin en fixtures/migración | Contradice "fixtures de datos de prueba, no credenciales": una contraseña fija en el repo es un incidente de seguridad esperando. |
| Login sin password para super_admin (whitelist de firewall) | Anula toda la autenticación justo donde más poder hay. |
| Mantener `^/admin → ROLE_ADMIN` a la espera del panel | Un rol de membresía no define capacidades globales; el permiso tenía que subir de nivel ahora, no al construir la UI. |
| Bypass de `RequireTenantListener` para *cualquier* usuario sin membresías | Rompería el onboarding: sin bypass el loop es real, con bypass general cualquiera saltaría el selector. |

## Consecuencias

- **Dos niveles de rol bien separados**: globales en `AppUser`
  (`ROLE_SUPER_ADMIN`), de negocio en `TenantMembership`
  (`ROLE_ADMIN`/`ROLE_USER`). `isGranted('ROLE_ADMIN')` sigue midiendo
  pertenencia a un negocio.
- **El super_admin ve la app "vacía"**: el dashboard responde con totales
  en cero; es el precio de no tener tenant activo, y el motivo por el que
  `/admin` es su destino natural cuando el panel exista.
- **El alta de usuarios es operación de CLI**: útil y auditable por
  terminal, pero no hay auto-registro; si llega, será una capa nueva
  sobre el mismo modelo (y este ADR no cambia).
- `TenantFilter` deshabilitado para este rol: cualquier código que asuma
  "filtro siempre activo" debe revisarse si se le permite correr en una
  request de super_admin (hoy solo `/` y `/admin`).
- **Sin recuperación de contraseña por email** todavía: el reset es
  `app:user:change-password` (operador a cargo).

## Referencias

- `src/Entity/AppUser.php` (`ROLE_SUPER_ADMIN`, `isSuperAdmin()`)
- `src/Command/UserCreateCommand.php`, `src/Command/UserChangePasswordCommand.php`, `src/Command/UserListCommand.php`
- `src/Controller/AdminController.php`, `src/Controller/DashboardController.php`
- `src/Doctrine/EventListener/TenantFilterListener.php`
- `src/EventListener/RequireTenantListener.php`, `src/EventListener/TenantSessionListener.php`
- `src/Repository/Contract/AppUserRepositoryInterface.php` (`findAll`)
- `src/Repository/Contract/TenantMembershipRepositoryInterface.php` (`findByTenant`)
- `config/packages/security.yaml` (access_control)
- `tests/Functional/Security/SuperAdminFlowTest.php`
- `tests/Integration/Command/`
- `tests/Unit/Repository/AppUserRepositoryInterfaceTest.php`
- Depende de: `docs/adr/ADR-0015-login-global-con-seleccion-de-tenant.md`
