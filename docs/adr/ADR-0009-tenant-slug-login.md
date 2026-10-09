# ADR-0009: Tenant resuelto por slug en el formulario de login (Opción B)

## Estado
Aceptado (Fase 1 UI).

## Contexto
La aplicación es multi-tenant: cada AppUser pertenece a un Tenant y el email
solo es único por tenant (constraint `uniq_user_tenant_email`). El login debe
identificar al usuario con (tenant, email). Opciones consideradas:

- **A) Subdominio por tenant** (`demo.app.com`): requiere configuración DNS y
  manejo de host por entorno; complejidad operativa alta para despliegues
  locales y testing.
- **B) Slug en el formulario de login**: campo visible `tenant_slug` junto a
  `email` y `password`. Cero infraestructura, testeable con BrowserKit.
- **C) Selector de tenant previo + login por email**: dos pasos, más fricción
  y estado intermedio que gestionar.

## Decisión
Opción B: campo `tenant_slug` en el formulario de login.

## Implementación
- `form_login` estándar de Symfony (CSRF, migración de sesión, hash de
  password, manejo de errores — todo lo del camino mantenido por el framework).
- `AppUserProvider::loadUserByIdentifier($email)` lee `tenant_slug` del
  request actual (`RequestStack`), resuelve el Tenant por slug y busca el
  usuario por email + tenant. Slug desconocido o usuario inexistente →
  `UserNotFoundException` → mensaje genérico de credenciales inválidas
  (sin enumaración de usuarios ni tenants).
- `AppUserChecker` rechaza usuarios inactivos (`DisabledException`).

### Aislamiento multi-tenant en `refreshUser` (Fase 1.5)
`refreshUser` corre en cada request autenticada y deshabilita el
`TenantFilter` alrededor de su lookup (en workers persistentes el filtro
puede venir habilitado con el tenant de la request anterior). Para no
confiar en el claim de tenant de la sesión:

- El usuario se carga por id **sin** filtro de tenant
  (`AppUserRepository::findById()`, único lookup cross-tenant permitido
  en repositorio, documentado en la interfaz).
- Se compara el tenant **persistido** contra el que declara el usuario
  de la sesión: si difieren → `UnsupportedUserException` (la sesión se
  cierra), en lugar de re-autenticar bajo el tenant reclamado.
- El filtro se restaura en `finally` en ambos lookups (antes y después
  de `loadUserByIdentifier` y de `refreshUser`).

Tests: `tests/Integration/Security/AppUserProviderTest.php` (sesión
manipulada → rechazo; usuario inexistente → `UserNotFoundException`;
filtro restaurado tras ambos lookups).

### Rate limiting de login (Fase 1.5)
- `login_throttling: {max_attempts: 5, interval: 15 minutes}` (por
  usuario+IP, con techo global del limiter). 15 minutos es el intervalo
  estándar para endpoints de auth: menos agresivo que 5 para usuarios
  con problemas de tipeo y suficiente para disuadir fuerza bruta.
- El bloqueo responde **429** con mensaje genérico
  ("Demasiados intentos fallidos. Probá de nuevo más tarde."), sin
  revelar si el email o el tenant existen. `LoginThrottlingResponseListener`
  se engancha a `LoginFailureEvent` (no a `kernel.exception`): el
  `AuthenticatorManager` captura la excepción del throttling y la
  convierte en un fallo de login con redirect antes de que llegue a
  `kernel.exception`, por lo que la respuesta se sobreescribe ahí.
- Test: `tests/Functional/Security/LoginThrottlingTest.php` (5 fallos +
  6.º con credenciales válidas → 429, mensaje genérico, sin filtrar).

## Consecuencias
- El provider depende de `RequestStack` solo en el flujo interactivo de login;
  es el patrón estándar para form_login multi-tenant y no contamina el
  resto de la app (el resto de consultas ya filtran por TenantContext).
- Un error de tipeo del slug produce el mismo mensaje genérico que una
  contraseña incorrecta: se acepta a cambio de no filtrar existencia de tenants.
- Si en el futuro se adoptan subdominios (opción A), solo cambia la resolución
  del slug en el provider (host → slug); el esquema de datos no se toca.

## Referencias
- `config/packages/security.yaml` (firewall main)
- `src/Security/AppUserProvider.php`
- `src/Security/AppUserChecker.php`
