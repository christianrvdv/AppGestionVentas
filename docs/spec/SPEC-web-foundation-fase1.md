# Spec: Web Foundation (Fase 1 UI)

## Objetivo
Primer chat de la capa de presentación: seguridad funcional (login multi-tenant
por slug), layout base, dashboard mínimo con contadores reales del dominio,
fixtures de demo y un test de humo HTTP. Nada más.

Usuario: operador de negocio autenticado (tenant Demo). Éxito: login con
slug+email+password redirige al dashboard, que renderiza totales del tenant
sin errores, con layout accesible.

## Fuera de alcance (no tocar)
Controllers/vistas de inversiones, ventas, pagos, mermas, productos, clientes,
reportes y settings; forms de creación; Turbo Frames/Streams; asset pipeline
completo; paginación (bundle se instala en Fase 2); deuda OBS-3 / ADR-0008.

## Decisiones fijadas (del brief, no se re-deciden)
- Tenant en login: slug en el formulario (opción B). ADR-0009.
- Twig + Tailwind + Turbo + Stimulus. Flash estándar de Symfony.
- Roles: ROLE_USER todos; ROLE_ADMIN en settings y tasas.
- Assets: el proyecto SÍ tiene pipeline local (asset-mapper +
  symfonycasts/tailwind-bundle) → NO se usa CDN. La condición del brief
  ("si no hay asset pipeline") no se cumple.

## Alcance y criterios de aceptación

### 1. Seguridad
- [ ] `AppUserProvider` carga por email + tenant slug (slug tomado del request
      de login vía RequestStack; refresh recarga por id+tenant).
- [ ] Firewall `main`: `form_login` con campos `tenant_slug`, `email`,
      `password`; path `/login`; éxito → `/`; error → flash + re-render.
- [ ] `logout` en `/logout` con CSRF habilitado; `remember_me` con cookie
      httpOnly, sameSite lax, secure auto.
- [ ] `access_control`: `/login` PUBLIC_ACCESS; `/admin/**` ROLE_ADMIN;
      catch-all ROLE_USER.
- [ ] Password hashing (auto), CSRF stateless (ids `authenticate`/`logout`
      ya en csrf.yaml), session fixation mitigation (migración de sesión
      que trae el framework), user checker rechaza usuarios inactivos.
- [ ] Rate limiting: NO aplicable — `symfony/rate-limiter` no está instalado
      (el brief lo condiciona a disponibilidad).

### 2. Layout base
- [ ] `templates/base.html.twig`: skip-link a `#contenido`, nav superior con
      logo y secciones (Dashboard link real; resto placeholder `aria-disabled`
      hasta Fase 2), área de flash messages, bloque `content` y `scripts`,
      Turbo Drive activo, textos en español.

### 3. Dashboard
- [ ] `DashboardController` en `/`: totales vía
      `InvestmentSummaryRepositoryInterface::sumTotalsByTenant()`
      (invertido, recuperado, pendiente, ganancia bruta, reconocida),
      tasa USD vigente vía `UsdRateService::getCurrent()`, inversiones
      activas vía `InvestmentRepositoryInterface::findActiveByTenant()`
      (link a detalle = placeholder hasta Fase 2), placeholder
      "próximamente" para gráficos. Sin lógica de negocio en la vista.

### 4. Fixtures (`doctrine:fixtures:load` sobre BD vacía)
- [ ] Tenant "Demo" slug `demo`; usuario `admin@demo.local` / `demo`
      (hash real, ROLE_ADMIN); settings `default_margin_pct=40`,
      `base_currency=ARS`; 3-5 productos; 2-3 clientes; 1 tasa USD.

### 5. Test de humo (WebTestCase, test-first)
- [ ] `GET /login` → 200.
- [ ] `POST /login` credenciales correctas → redirect `/`.
- [ ] `GET /` sin sesión → redirect `/login`.
- [ ] `GET /` autenticado → 200 y contiene "Dashboard".

## Comandos
- Tests: `php bin/phpunit` (Unit + Integration + Functional).
- Lint: `bin/console lint:twig templates/`, `bin/console lint:container`.
- Esquema: `bin/console doctrine:schema:validate`.
- Fixtures: `bin/console doctrine:fixtures:load --no-interaction`.

## Estructura
- `src/Security/` → provider y user checker.
- `src/Controller/` → SecurityController, DashboardController.
- `src/DataFixtures/` → fixtures por entidad.
- `templates/security/login.html.twig`, `templates/dashboard/index.html.twig`.
- `tests/Functional/` → smoke tests (suite nueva en phpunit.dist.xml).
- `docs/adr/ADR-0009-tenant-slug-login.md`.

## Estrategia de tests
Functional (WebTestCase + BrowserKit) para flujos HTTP; sin tocar la suite
de dominio existente (111 tests deben seguir verdes). SQLite in-memory del
phpunit.dist.xml; `disableReboot()` para conservar la BD entre requests.

## Límites
- Siempre: tests antes de dar por hecho un incremento; textos en español;
  contraste AA; sin lógica de negocio en Twig.
- Preguntar antes: tocar entidades/repos/servicios del dominio; migraciones.
- Nunca: secrets commiteados; tocar ADR-0008/OBS-3; borrar tests existentes.

## Criterios de aceptación globales (DoD)
Login con tenant slug funciona; dashboard renderiza; fixtures cargan sobre BD
vacía; smoke tests verdes; lint:twig/lint:container/schema:validate OK;
login accesible (labels, focus visible, contraste AA, autocomplete); ADR-0009
commiteado; un commit `feat(web): seguridad, layout base, dashboard y fixtures (Fase 1 UI)`.

## Preguntas abiertas
Ninguna bloqueante. Pendientes documentados: knp-paginator (Fase 2), links de
nav y detalle de inversión (Fase 2), rate-limiter (evaluar en Fase 2).
