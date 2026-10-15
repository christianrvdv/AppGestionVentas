# ADR-0021: Navegación con sidebar persistente

- **Estado:** Aceptado.
- **Fecha:** 2026-10-15.
- **Contexto:** la navegación vivía como navbar inline en
  `templates/base.html.twig` (110 líneas dentro de la plantilla base, ~11
  destinos + menú de reportes + admin + negocio + logout). Cada ítem
  repetía su propia lógica de "activo" con clases inline, los estilos de
  botón estaban duplicados en decenas de plantillas (y `btn-primary` /
  `btn-secondary` no existían en el CSS: generaban 0 bytes), y no había
  `aria-current` en ninguna parte. La spec aprobada
  (`docs/spec/SPEC-sidebar-navegacion.md`) fija sidebar lateral + topbar
  mínimo; este ADR registra el porqué y las alternativas.

## Problema

1. **El navbar horizontal no escalaba.** Nueve enlaces + dropdown de
   reportes + admin + negocio + logout en una fila; con anchos medios ya
   hacía wrap (`flex-wrap` + `gap-y-2`) y cada sección nueva empeoraba el
   problema. Los grupos semánticos (Operación / Catálogo / Reportes /
   Sistema) eran invisibles para el usuario.
2. **DRY inexistente en estilos.** `btn-primary` se escribía a mano en
   ~21 plantillas (mismo `rounded bg-brand px-4 py-2 font-medium text-white
   hover:bg-brand/90 focus-visible:ring-2 ...`), `btn-secondary` en dos
   plantillas, y ningún duplicado correspondía a una regla CSS real. El
   token `--color-warning` tampoco existía: sus 6 usos en plantillas
   (banner de impersonation) no producían bytes de CSS.
3. **Accesibilidad.** La sección activa se marcaba con
   `font-semibold text-brand` (perdido al copiar, invisible para lectores
   de pantalla) y el único `aria-label="Navegación principal"` lo
   dependían tests funcionales.

## Decisión

1. **Sidebar fijo agrupado** con los cuatro grupos y los 12 destinos
   documentados en la spec, dentro de `<nav aria-label="Navegación
   principal">` (sigue siendo el único nav con esa etiqueta).
   **Topbar mínimo** (`<header><nav aria-label="Acciones de la cuenta">`):
   hamburguesa, logo → dashboard, selector de negocio, Admin (solo
   super_admin) y user menu.
2. **`aria-current="page"` + `.nav-link-active` como único indicador de
   sección activa.** Una sola computación por destino
   (`route starts with 'app_x_'` o igualdad) en `_sidebar.html.twig`, no
   once repeticiones. `.nav-link-active` se define después de `.nav-link`
   dentro de `@layer components` (el orden de fuente decide a igual
   specificity).
3. **Clases semánticas en `@layer components`** (`btn-primary`,
   `btn-secondary`, `btn-ghost`, `card`, `input`, `empty-state`,
   `nav-link`, `nav-link-active`) con `@apply` de los patrones ya
   existentes: cero drift visual, y las utilities de Tailwind v4 siguen
   pudiendo sobrescribir a nivel de llamada (`btn-primary px-6`). Se
   añade `--color-warning: #b45309` (amber-700, registro 700 de la misma
   familia que brand/success/danger).
4. **DRY de plantillas por parciales**: `_sidebar.html.twig`,
   `_topbar.html.twig`, `_nav_item.html.twig` (macro con badge opcional,
   soportado pero sin uso — sin métricas falsas) e
   `_impersonation_banner.html.twig`. `base.html.twig` queda como
   orquestador de ~53 líneas: skip-link, banner, wrapper con
   `data-controller="sidebar"`, dos includes condicionales a
   `app.user` y un único `<main>`.
5. **Estado de UI sin Turbo Frames.** El colapso del desktop persiste en
   `localStorage` (`gd.sidebar.collapsed`) y el cajón móvil se abre/cierra
   con `data-open` + focus-trap + Escape (controlador Stimulus propio,
   `sidebar_controller.js`). El estado inicial de móvil (oculto) vive en
   CSS por viewport, no en JS: no hay flash. Turbo Drive navega
   completo; no se justificó ningún Turbo Frame (la spec lo exige con
   métrica).
6. **User menu = disclosure** con el `dropdown_controller` existente
   (trigger = email, items "Cambiar contraseña" y "Cerrar sesión"). Sin
   `role="menu"`: el form de logout dentro de un `role="menu"` viola ARIA
   (solo admite menuitems), y el patrón disclosure cubre el caso.

## Alternativas consideradas

- **Mantener el navbar horizontal y paginarlo/colapsarlo:** rechazado;
  no resuelve la falta de grupos ni el crecimiento por sección nueva.
- **Sidebar fijo (`position: fixed`) + contenido con margen izquierdo:**
  rechazado a favor de flex + `sticky`; el flujo del contenido no depende
  de reglas de margen replicadas.
- **Turbo Frames para el cajón móvil:** rechazado — no hay contenido
  remoto que traer, un frame añadiría un flash de render y la spec
  prohíbe frames sin métrica que los justifique.
- **Librería de iconos (lucide, etc.):** rechazado — no se instala
  dependencias del proyecto; se usan SVG inline con `stroke="currentColor"`
  y `aria-hidden="true"` (decorativos; el nombre accesible lo da
  `.sidebar-label`, que en modo colapsado pasa a `sr-only` vía CSS, no
  `display: none`).
- **Dark mode:** fuera de alcance (decisión explícita de pre-flight).

## Consecuencias

- `PaymentIndexTest::testIndexMarksPaymentsNavAsActive` pasó de asertar
  `font-semibold text-brand` a `assertSame('page', $nav->attr('aria-current'))`:
  es el cambio de test justificado por la spec (medía implementación,
  ahora mide contrato accesible). Los demás tests de navegación
  (`SaleIndexTest:208`, `TenantSelectionTest` con `header nav`,
  `AdminDashboardTest` con `header a[href="/admin"]`,
  `ReportPagesTest:63` con `.btn-secondary`) no cambiaron: los
  selectores se preservaron deliberadamente.
- `tests/Functional/Navigation/SidebarNavigationTest.php` es la red de
  seguridad de refactorizar parciales (8 tests: grupos, destinos,
  aria-current único, topbar, toggles, nombres accesibles, login sin
  sidebar).
- `btn-primary`/`btn-secondary` **ya tienen CSS real**, así que
  `report/base.html.twig` y las migraciones futuras usan la clase sin
  riesgo. Deuda restante: los ~21 duplicados inline de botón y los
  `text-foreground hover:text-brand` de enlaces activos legados, a
  migrar al tocar cada plantilla (no en esta fase, para no mezclar
  alcances).
- El topbar no es `sticky` (paridad con el header anterior); el sidebar
  sí lo es en desktop. Cambiarlo es decisión futura, no implícita.
- `sidebar_controller.js` escucha `keydown` en `document` solo con el
  sidebar presente (guardia `hasPanelTarget`): las páginas anónimas no
  registran listeners.

## Referencias

- `docs/spec/SPEC-sidebar-navegacion.md` — spec aprobada (mapa de
  información, criterios de aceptación, plan de tests).
- ADR-0019 — Impersonation de tenant (banner extraído a parcial).
- ADR-0020 — Formularios en rutas Turbo (límite Turbo Drive/Frames).
- Commits: `bd78680` (spec), `89d0f75` (tokens/clases), `7efad79`
  (sidebar+topbar), `a95fc0c` (parcial banner), `96921e3` (tests).

## Deuda conocida (no urgente, no bloquea)

- Migrar los duplicados inline de botones/enlaces a las clases
  semánticas al tocar cada plantilla.
- El dropdown de reportes (heredado) conserva `role="menu"` con solo
  `<a>`: válido hoy, pero su teclado es Tab y no flechas (patrón APG de
  menú). No se tocó por alcance; si se migra a disclosure, unificar con
  el user menu.
