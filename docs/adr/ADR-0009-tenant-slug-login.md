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
- `refreshUser()` (sesión) recarga por id + tenantId, sin depender del request.
- `AppUserChecker` rechaza usuarios inactivos (`DisabledException`).

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
