# ADR-0015: Login global con selección de tenant post-login

- **Estado:** Aceptado (Fase 3.5). **Reemplaza a ADR-0009** (slug en el login).
- **Fecha:** 2026-10-09 (Fase 3.5)
- **Contexto:** Fase 3.5 — el usuario pasa a ser identidad global y la
  pertenencia a negocios vive en una tabla puente.

## Problema

Con ADR-0009 el login pedía `(tenant_slug, email, password)` y `AppUser`
tenía `tenant_id` propio con el índice único `uniq_user_tenant_email
(tenant_id, email)`. Consecuencias prácticas:

- **Un usuario = un negocio.** No se podía atender dos locales con la misma
  cuenta: había que crear dos usuarios y recordar dos slugs.
- **El slug es conocimiento que el usuario no tiene.** "¿Cuál es el slug de
  mi negocio?" es una pregunta que un dueño no debería tener que responder
  para entrar.
- El email no era único en el sistema, así que "recuperar contraseña" o
  "invitar a un socio" no tenían una identidad global a la que apuntar.

## Decisión

Separar **identidad** (global) de **pertenencia** (por negocio), y elegir el
negocio activo *después* del login.

| Concepto | Dónde vive |
| --- | --- |
| Identidad del usuario | `AppUser` con `email` **único en todo el sistema** (`uniq_user_email`) |
| Pertenencia a un negocio | Tabla puente `tenant_membership` (`user_id`, `tenant_id`, `role`, `is_default`, `joined_at`; única en `(user_id, tenant_id)`) |
| Negocio activo | Claim de sesión `active_tenant_id`, elegido en `/select-tenant` |
| Login | `email` + `password`, sin slug |

## Implementación

### Modelo de datos

`TenantMembership` es la entidad puente con `ManyToOne` a `AppUser` y a `Tenant`.
`AppUser` ya **no** tiene `tenant_id`; `Tenant` expone `memberships`. El rol
de membresía (`ROLE_ADMIN` / `ROLE_USER`) es el que autoriza acciones dentro
del negocio — distinto del rol del `AppUser`, que define capacidades globales.

El nombre de las FK lo computa Doctrine por convención y la migración los
escribe **sin comillas** (`FK_7EBE842DA76ED395`), de modo que PostgreSQL los
pliega a minúsculas y coinciden con los que genera `doctrine:schema:validate`
desde los metadatos. Mismo criterio para `DROP CONSTRAINT`/`DROP INDEX` de los
índices viejos (`FK_88BDF3E99033212A`, `IDX_88BDF3E99033212A`).

### Login sin slug

`AppUserProvider::loadUserByIdentifier($email)` busca **solo por email**.
Los lookups que son por identidad y no por negocio (`loadUserByIdentifier`,
`refreshUser`) pasan por `TenantFilterGuard::withoutTenantFilter()`, el único
punto del código autorizado a apagar el `TenantFilter`, con restauración en
`finally` (importa en workers persistentes y en tests con kernel compartido).

`refreshUser` **ya no** compara tenant persistido vs. reclamado (eso era
correcto con un tenant por usuario, ahora es falso). En su lugar valida que
el `active_tenant_id` de sesión, si existe, corresponda a una membresía real
del usuario; si no, descarta el claim.

### Selector de tenant y listener de sesión

- `TenantSessionListener` se engancha a `LoginSuccessEvent` (guarda
  `active_tenant_id` cuando el usuario tiene exactamente **una** membresía, o
  lo limpia para forzar el selector) y a `LogoutEvent` (limpia el claim).
- `RequireTenantListener` (`kernel.request`, prioridad 6, después del
  firewall) intercepta las rutas de negocio cuando **no** hay tenant activo y
  redirige a `/select-tenant` (o a `/onboarding` si no hay membresías).
  Whitelist: paths exactos `/login`, `/logout`, `/onboarding`; **prefijos**
  `/_profiler`, `/_wdt`, `/_error`, `/assets`, `/build`, `/select-tenant`.
- `ActiveTenantSession` (sesión) + `ActiveTenantProvider` exponen el tenant
  activo; los servicios (`InvestmentCreationService`, `PaymentService`,
  `SaleService`, controllers de producto/cliente) ya no leen tenant del user,
  sino del provider.

### `/select-tenant` y `/onboarding`

- `SelectTenantController` lista las membresías del usuario (CSRF en el
  form de selección) y `POST /select-tenant/{id}` guarda el claim. Una
  membresía ajena → 404 (sin filtrar existencia).
- `OnboardingController` crea el primer negocio cuando el usuario no tiene
  membresías: crea `Tenant` + `TenantMembership` (`ROLE_ADMIN`,
  `is_default=true`), guarda `active_tenant_id` y deriva el `slug` del nombre
  si se deja vacío (rechazando slugs tomados).

## Migración de datos

`Version20261009090000` (orden importa):

1. Crea `tenant_membership` + índices/FK.
2. Migra datos: cada usuario existente queda como miembro (y dueño:
   `ROLE_ADMIN`, `is_default=true`) de su tenant actual.
3. Cambia la unicidad de usuarios: `(tenant_id, email)` → `(email)`.
4. Recién ahí dropea `app_user.tenant_id`.

Verificada sobre **BD vacía** (schema desde metadatos) y sobre **dev con el
tenant Demo**: `admin@demo.local` → miembro `ROLE_ADMIN` de `demo`,
`is_default=true`, y `doctrine:schema:validate` sin drift.

## Alternativas descartadas

| Alternativa | Por qué no |
| --- | --- |
| Subdominio por tenant (`demo.app.com`) | Requiere DNS y manejo de host por entorno; complejidad alta para despliegues locales y testing (ya descartada en ADR-0009). |
| Slug en el login (ADR-0009) | Un usuario queda amarrado a un solo negocio y debe conocer el slug. |
| Un `AppUser` por (usuario, negocio), como antes | Duplica identidad: no hay "recuperar contraseña" ni "invitar socio" globales. |
| Elegir tenant en el login (dos pasos antes de entrar) | Fricción y estado intermedio; el selector post-login es el patrón habitual (Slack, Notion). |
| Claim de tenant solo en el filtro, sin sesión | El filtro se re-habilita por request; el negocio activo es estado de usuario, no de la query. |

## Consecuencias

- **Un usuario puede pertenecer a N negocios** y cambiar entre ellos sin
  volver a autenticarse; el negocio activo es un claim de sesión, no un dato
  de la entidad.
- **El email pasa a ser la identidad global**: simplifica recuperación de
  contraseña e invitaciones, pero dos negocios distintos no pueden tener
  "el mismo usuario" como cuentas separadas — es una persona, con dos
  membresías.
- `refreshUser` ya no valida "tenant reclamado vs. persistido" (inaplicable
  ahora); el aislamiento lo garantiza `TenantFilter` + `RequireTenantListener`
  + la validación de membresía.
- `TenantFilterGuard` es el único punto autorizado a apagar el filtro; cualquier
  nuevo lookup por usuario (no por negocio) debe pasar por él.
- Si en el futuro se adoptan subdominios, solo cambia cómo se preselecciona
  el tenant (host → membresía por slug de `Tenant`); el esquema de datos no
  se toca.

## Referencias

- `config/packages/security.yaml` (firewall main)
- `src/Security/AppUserProvider.php`, `src/Security/ActiveTenantSession.php`
- `src/Doctrine/TenantFilterGuard.php`, `src/Doctrine/EventListener/TenantFilterListener.php`
- `src/EventListener/RequireTenantListener.php`, `src/EventListener/TenantSessionListener.php`
- `src/Controller/SelectTenantController.php`, `src/Controller/OnboardingController.php`
- `src/Service/ActiveTenantProvider.php`
- `migrations/Version20261009090000.php`
- `tests/Functional/Security/TenantSelectionTest.php`
- Reemplaza: `docs/adr/ADR-0009-tenant-slug-login.md`
