# Spec: Navegación con sidebar persistente

Estado: **PENDIENTE DE APROBACIÓN** — no se toca código hasta que se apruebe.

## Objetivo

Sustituir el navbar horizontal inline de `templates/base.html.twig` (líneas 49–159)
por un **sidebar lateral persistente** + **topbar mínima**, con DRY (parciales Twig
y clases semánticas en CSS), `aria-current="page"` en el item activo, y navegación
vía Turbo Drive (ya activo).

Usuario: operador de negocio autenticado. Éxito: llega a cualquier sección en un
click desde cualquier pantalla, en desktop y móvil, con teclado, sabiendo siempre
dónde está; `base.html.twig` queda como orquestador <60 líneas; tests verdes.

## No-objetivo (fuera de alcance)

- Dark mode (deuda documentada; duplicaría trabajo de tokens).
- Turbo Frames: **NO en este PR**. Justificación: Turbo Drive ya cubre la
  navegación SPA completa; no existe un caso medible (latencia de fragmento,
  contadores que refrescar) que justifique la complejidad. Se revisará si aparece.
- Refactor de los ~74 templates con `card`/`input` inline: solo se usan las
  clases nuevas en los archivos que este trabajo toque; el resto queda deuda.
- Nuevas dependencias (sin librerías de iconos ni UI): SVGs inline estilo
  Heroicons (MIT, ya el estilo del proyecto — ver chevron en `base.html.twig`).
- `security.yaml`, `csrf.yaml`, `importmap.php`, rutas, controladores PHP: intactos.
- AJAX manual / `fetch()`.

## Decisiones de pre-flight (confirmadas por defecto)

1. **Topbar mínima: SÍ.** Contenido: logo/nombre → dashboard, Admin (solo
   superAdmin), selector de negocio, user menu (dropdown). Banner de
   impersonation encima de todo, ancho completo (extraído a partial, id
   `#banner-impersonation` intacto — lo aserta `AdminImpersonationTest:45,52,71`).
2. **Sidebar colapsable en desktop** (solo iconos, persistencia `localStorage`
   clave `gd.sidebar.collapsed`) + **overlay en móvil** (<768px, focus-trap).
3. **Dark mode: NO.**
4. **Turbo Frames: NO.**

## Mapa de información (IA)

Sidebar con `aria-label="Navegación principal"` (mismo label que hoy →
`SaleIndexTest:208` y `PaymentIndexTest:235` siguen aplicando). Grupos con
separadores y etiqueta visible asociada (`aria-labelledby`):

| Grupo | Items | Ruta / match |
|---|---|---|
| **Operación** | Dashboard | `app_dashboard` (exacta) |
| | Inversiones | `app_investment_*` (prefijo) |
| | Ventas | `app_sale_*` (prefijo) |
| | Pagos | `app_payment_*` (prefijo) |
| **Catálogo** | Productos | `app_product_*` |
| | Clientes | `app_customer_*` |
| | Tasas USD | `app_usd_rate_*` |
| **Reportes** | Ganancias | `app_report_profit` (exacta) |
| | Inversiones | `app_report_investment` (exacta) |
| | Productos | `app_report_product` (exacta) |
| | Revaluación USD | `app_report_revaluation` (exacta) |
| **Sistema** | Configuración | `app_settings_*` |

Topbar (utility nav, dentro de `<header>` con `<nav>` interno — lo exige
`TenantSelectionTest` con `header nav` + texto del negocio):

- Izquierda: nombre del proyecto → `app_dashboard`; en <768px, botón hamburguesa
  (`aria-label`, `aria-expanded`, `aria-controls="sidebar-panel"`).
- Derecha: Admin → `app_admin_index` (solo `app.user.superAdmin` — preserva
  `AdminDashboardTest:94,105` que aserta `header a[href="/admin"]`); selector de
  negocio → `app_select_tenant` con `active_tenant.name ?? 'Sin negocio'`; user
  menu (reusa `dropdown_controller`) con "Cambiar contraseña"
  (`app_password_change`) y "Cerrar sesión" (POST `_logout_main`, CSRF).

**Desviaciones de la agrupación propuesta en la misión, justificadas:**

- *Análisis → Reportes*: el nombre del grupo hace de desambiguador visual y para
  lectores de pantalla entre "Inversiones/Productos" (operación/catálogo) y sus
  homónimos de reporte. Regla de labeling: una palabra por concepto.
- *Admin → topbar*: la misión §5 lo coloca en el topbar y §1 lo sugiere en el
  sidebar. Prevalece §5: destino global, poco frecuente, y mantiene
  `AdminDashboardTest` sin cambios.
- *Cuenta → topbar*: utility nav (IA skill: cuenta/logout arriba, no en la nav
  de contenido).

El dropdown de Reportes deja de existir: en sidebar todo es visible de serie
(NN/g: nav oculta = menos descubrimiento; un dropdown dentro de un drawer añade
un click innecesario). `dropdown_controller.js` sigue vivo, usado por el user
menu del topbar — no se duplica lógica.

## Parciales Twig (DRY)

- `templates/_partials/_impersonation_banner.html.twig` (extrae `base:22–47`)
- `templates/_partials/_nav_item.html.twig` — macro `nav_item(route, label, icon,
  match?, badge?)`; calcula activo con `app.request.attributes.get('_route')`
  (exacta o prefijo `match`), emite `aria-current="page"` + clase
  `nav-link-active`. `badge` soportado pero sin uso hoy (sin métricas falsas).
- `templates/_partials/_sidebar.html.twig` — `<aside id="sidebar-panel">` con
  `<nav aria-label="Navegación principal">`, grupos, botón de colapso
  (`aria-expanded` + `aria-controls`), overlay de móvil.
- `templates/_partials/_topbar.html.twig` — `<header>` con nav de utility.

`base.html.twig` queda orquestador: skip-link, include de banner, include de
sidebar (si `app.user`), include de topbar (si `app.user`), `<main id="contenido">`
con flashes, blocks. **Objetivo: <60 líneas.** Skip-link intacto.

## Tokens y clases semánticas (`assets/styles/app.css`)

- `--color-warning: #b45309` en `@theme` (Tailwind amber-700). Justificación:
  es el equivalente 700 de la familia de la paleta existente, toda a nivel 700:
  brand `#4338ca` (indigo-700), success `#15803d` (green-700), danger `#b91c1c`
  (red-700). Hoy las 6 usadas en templates (`text-warning`, `bg-warning/10`,
  `border-warning/40`…) **generan 0 bytes de CSS** (verificado en
  `var/tailwind/app.built.css`: 0 coincidencias "warning") — bug real que esto
  arregla.
- Clases en `@layer components` (CSS plano, sin `@apply`; la capa `utilities`
  de Tailwind v4 las sobreescribe a nivel de llamada, ej. `btn-primary px-6`):

| Clase | Estilo base (hoy duplicado inline) |
|---|---|
| `btn-primary` | sólido brand: `bg-brand text-white px-4 py-2 font-medium rounded hover:bg-brand/90 focus-visible:ring-2 ring-brand` (los 21 usos inline) |
| `btn-secondary` | outline en surface: `border border-border bg-surface px-4 py-2 text-sm font-medium text-foreground hover:bg-muted focus-visible:ring-2 ring-brand`. **Nota**: `report/base:15-16` ya usa `btn-primary`/`btn-secondary` pero hoy están **sin estilos** (0 CSS generado) — `ReportPagesTest:63` solo aserta la clase en HTML, así que definirlas arregla los botones de filtro sin tocar tests |
| `btn-ghost` | `text-muted px-3 py-2 rounded hover:text-foreground hover:bg-muted/50 focus-visible:ring-2 ring-brand` |
| `card` | `rounded border border-border bg-surface p-4` (74 usos deuda) |
| `input` | `rounded border border-border bg-surface px-3 py-2 text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand` (sin width; el llamador añade `w-full`/`w-32`) |
| `empty-state` | `rounded border border-border bg-surface px-4 py-3 text-sm text-muted` |
| `nav-link` | `flex items-center gap-2 rounded px-3 py-2.5 text-sm text-foreground hover:bg-muted/50 hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand` |
| `nav-link-active` | `bg-brand/10 font-semibold text-brand` (siempre junto a `aria-current="page"`) |

Todos los focus siguen el patrón del proyecto: `focus-visible:outline-none` +
`ring-2 ring-brand` visible (regla Vercel: nunca quitar outline sin
reemplazo — el ring es el reemplazo).

## Sidebar: HTML + Stimulus

- Estructura: `nav > (p etiqueta de grupo[id] + ul[aria-labelledby]) > li > a.nav-link`;
  SVGs Heroicons inline con `aria-hidden="true"`, `stroke="currentColor"`.
- Activo: `aria-current="page"` (criterio 5) + `nav-link-active`.
- **Desktop (≥768px)**: sticky, colapsable. Botón (chevron) con
  `aria-expanded` + `aria-controls="sidebar-panel"`; al colapsar: ancho → iconos
  solos (labels ocultos vía atributo `data-collapsed` + CSS), estado persistido
  en `localStorage`. Riesgo aceptado: un frame expandido en el primer paint tras
  recargar (sin scripts inline — simplicidad).
- **Móvil (<768px)**: el estado visual inicial (oculto) lo da CSS por media
  query (sin flash de JS). Botón hamburguesa del topbar abre: `data-open`,
  overlay visible, panel `role="dialog" aria-modal="true"` **solo en móvil**,
  foco atrapado dentro del panel, foco inicial en el primer link, cierre con
  Escape / click en overlay / navegación, foco devuelto a la hamburguesa,
  `overscroll-behavior: contain` en el drawer.
- Transición: `transform` 200ms ease-out, `motion-reduce:transition-none`
  (guidelines Vercel: `prefers-reduced-motion`, animar solo transform/opacity).
- **Controlador nuevo** `assets/controllers/sidebar_controller.js` (targets
  `panel`, `toggle`, `overlay`). Evaluación de reuso de `dropdown_controller`:
  no aplica para el sidebar (no tiene focus-trap, persistencia ni overlay) y su
  lógica no se copia: se mantiene intacto para el user menu del topbar.
- Con Turbo Drive el body se reemplaza → Stimulus reconecta y reaplica estado.

## Estados de interacción (matriz)

| Elemento | Default | Hover | Focus | Activo/actual | Otros |
|---|---|---|---|---|---|
| `nav-link` | texto foreground, icono muted | `hover:bg-muted/50 hover:text-brand` | ring brand visible | `aria-current` + `bg-brand/10 text-brand font-semibold` | — |
| Botón colapsar | chevron + `aria-expanded="false"` | bg-muted | ring | expanded→true, layout estrecho | persiste en localStorage |
| Hamburguesa | solo <768px, `aria-expanded="false"` | bg-muted | ring | drawer abierto, overlay visible, foco atrapado | Escape/click cierran, foco restaurado |
| `btn-primary/secondary/ghost` | ver arriba | variación de color | ring | — | sin estado loading (no aplica a links) |

## Accesibilidad (requisitos duros)

- `aria-current="page"` en el item activo; sin exceptions.
- Nombres accesibles: botones icon-only con `aria-label`; SVGs decorativos
  `aria-hidden="true"`; links con texto meaningful.
- Teclado: todo alcanzable con Tab en orden visual; focus visible siempre;
  `tabindex` solo 0/natural; Escape cierra el drawer.
- Drawer móvil: focus-trap, foco inicial dentro, restauración al cerrar,
  `role="dialog" aria-modal="true"`.
- Contraste AA verificado sobre los tokens: brand sobre surface ≈9:1, muted
  `#64748b` sobre blanco 4.76:1, warning `#b45309` sobre blanco ≈4.8:1,
  white sobre brand ≈9:1.
- El estado actual no se comunica solo con color: `aria-current` + peso
  (`font-semibold`) + fondo.

## Tests

**Actualizados (cada cambio justificado en su commit):**

1. `PaymentIndexTest:238` — cambia `assertStringContainsString('font-semibold
   text-brand', $nav->attr('class'))` por `assertSame('page',
   $nav->attr('aria-current'))`. Justificación: acoplaba el test a clases de
   estilo; el contrato accesible ahora es `aria-current` (criterio 5). El resto
   del test (`nav[aria-label=…] a[href="/pagos"]`, count 1) no cambia.
2. `SaleIndexTest:208` — **sin cambio** (`assertGreaterThan(0, …)`, selector
   compatible con el sidebar).
3. `ReportPagesTest:63` — **sin cambio** (`a.btn-secondary` se conserva en
   `report/base.html.twig`).
4. `TenantSelectionTest` (`header nav` ×4, botón `Cerrar sesión`) y
   `AdminDashboardTest` (`header a[href="/admin"]` ×2) — **sin cambio** por el
   diseño del topbar.
5. `AdminImpersonationTest` (`#banner-impersonation` ×3) — cubre el banner;
   extraerlo a partial no altera el DOM. **Sin duplicar** ese caso en el test
   nuevo.

**Nuevo** `tests/Functional/Navigation/SidebarNavigationTest.php`:

- GET `/`, `/ventas`, `/productos`, `/reportes/ganancias` con login → el sidebar
  renderiza y `nav[aria-label="Navegación principal"] a[aria-current="page"]`
  apunta a la ruta esperada (cubre match exacta y por prefijo, y grupo Reportes).
- El sidebar contiene los 11 links esperados (incluidos los 4 de reportes).
- Sin sesión (`app.user` null): no hay sidebar (count 0) y la respuesta no
  rompe (login/redirección).
- Admin link presente solo para superAdmin, ausente para admin de negocio
  (refuerzo del patrón de `AdminDashboardTest`, a nivel del nuevo marcado).

`SidebarAriaTest` con navegador real (Playwright): **opcional** — solo si
Playwright CLI responde en este entorno; si no, se anota y se salta.

## Criterios de aceptación (medibles)

1. `base.html.twig` < 60 líneas.
2. Existen los 4 parciales nuevos.
3. Existen `btn-primary`, `btn-secondary`, `btn-ghost`, `card`, `input`,
   `empty-state`, `nav-link`, `nav-link-active` en `app.css`.
4. `--color-warning` definido; `tailwind:build` y grep en
   `var/tailwind/app.built.css` muestra `--color-warning` y las utilidades
   `warning` (>0 coincidencias; hoy: 0).
5. `aria-current="page"` en el item activo (test lo asierta).
6. Responsive: <768px overlay + focus-trap; ≥768px colapsable con persistencia.
7. `php bin/phpunit` verde (con los cambios de test justificados), y también con
   `--order-by=random`.
8. `php bin/console lint:twig templates/` OK.
9. `php bin/console lint:container` OK.
10. `php bin/console tailwind:build` sin warnings de tokens huérfanos.
11. `php bin/phpstan` sin errores nuevos (level 6 `src/`, level 5 `tests/`).

## Commands

```
php bin/console tailwind:build          # CSS a var/tailwind/app.built.css
php bin/console lint:twig templates/
php bin/console lint:container
php bin/phpunit --order-by=random       # suite completa (pre-commit)
php bin/phpstan
```

## Commits (atómicos, ASCII-only)

1. `docs(spec): spec sidebar navegacion`
2. `feat(nav): tokens y clases semanticas` (app.css)
3. `feat(nav): sidebar y topbar` (parciales, orquestador, controller, fix de
   `PaymentIndexTest` justificado en el cuerpo)
4. `refactor(nav): extraer parciales` (banner de impersonation)
5. `test(nav): cobertura sidebar`
6. `docs(adr): 0021`

## Deuda documentada (no en este PR)

- ~74 templates con `card`/`input`/`btn-*` inline → migración incremental.
- 21 botones `bg-brand` duplicados fuera de los archivos tocados.
- Dark mode (tokens duplicados pendientes).
- Verificación visual con Playwright solo si el CLI responde; si no, checklist
  manual de URLs: `/`, `/inversiones`, `/ventas`, `/pagos`, `/productos`,
  `/clientes`, `/reportes/ganancias`, `/tasas-usd`, `/configuracion`, `/admin`
  (super_admin), `/select-tenant`, `/cambiar-password`.

## Decisiones cerradas en la aprobación

1. **Admin en topbar** — aprobado (§5 de la misión; `AdminDashboardTest` sin
   cambios).
2. **User menu dropdown** — aprobado: trigger con el email del usuario,
   contiene "Cambiar contraseña" y "Cerrar sesión"; reutiliza
   `dropdown_controller`.
3. Grupos `Operación / Catálogo / Reportes / Sistema` — aprobados con las
   desviaciones justificadas arriba.
