# DESIGN.md — Sistema de diseño de Gestión de Negocio

> Fuente de verdad para todas las fases (1-7). Si algo no está aquí, no se usa.
> Anchor aesthetic: **Nordic**. Tailwind v4 CSS-first, sin Node, sin config.
> Archivo fuente: `assets/styles/app.css`. Compilado: `var/tailwind/app.built.css`.

---

## 1. Aesthetic anchor: Nordic

Herramienta de gestión de negocio de uso diario, densidad media-alta.
Prioriza claridad, confianza y funcionalismo sobre experimentación.
Líneas limpias, bordes sutiles, un solo acento (indigo), sin ornamento.

---

## 2. Paleta

### Tokens semánticos (usar estos en templates)

| Token | Hex | Uso |
|---|---|---|
| `--color-background` | `#f8fafc` | Fondo de página |
| `--color-foreground` | `#0f172a` | Texto principal |
| `--color-surface` | `#ffffff` | Fondo de cards, tablas, formularios |
| `--color-border` | `#e2e8f0` | Bordes de superficies |
| `--color-muted` | `#64748b` | Texto secundario, placeholders |
| `--color-brand` | `#4338ca` | Acento interactivo (links, botones primarios, focus ring) |
| `--color-success` | `#15803d` | Estados positivos |
| `--color-danger` | `#b91c1c` | Errores, acciones destructivas |
| `--color-warning` | `#b45309` | Advertencias |
| `--color-success-surface` | `#f0fdf4` | Fondo de alertas/badges success |
| `--color-warning-surface` | `#fffbeb` | Fondo de alertas/badges warning |
| `--color-danger-surface` | `#fef2f2` | Fondo de alertas/badges danger |

### Escalas primitivas (disponibles como utilities: `bg-brand-100`, etc.)

Brand (indigo) y Neutral (slate): escala completa 50-900.
Success/danger/warning: pasos 50, 100, 500, 600, 700.

> `--color-brand-surface` fue eliminado: token sin consumidor = deuda.
> Si una fase necesita un fondo brand-50, usar `bg-brand-50` directamente.

---

## 3. Tipografía

Fuente: `ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`.

| Clase | Tamaño | Peso | Uso |
|---|---|---|---|
| `heading-1` | 1.5rem (24px) | 600 | Título de página |
| `heading-2` | 1.125rem (18px) | 500 | Título de sección |
| `heading-3` | 1rem (16px) | 500 | Subtítulo |
| `muted-text` | 0.875rem (14px) | 400 | Texto descriptivo |
| `small-text` | 0.75rem (12px) | 400 | Captions, ayuda |

Cuerpo por defecto: 0.875rem (14px) — densidad media-alta.

---

## 4. Espaciado y radios

Tailwind v4 ya define la escala base (0.25rem = 4px). No se redefine.

**Convenciones:**
- Padding de card: `p-4` (16px). Card amplia: `p-6` (24px).
- Gap entre cards: `gap-4` (16px).
- Margin-bottom de sección: `mb-6` o `mb-8`.
- Border radius de superficies: `rounded` (4px). Modal: `rounded-lg` (8px).
- Badge: `rounded-full` (pill).

---

## 5. Sombras y elevación

Nordic usa sombras con moderación. Solo donde hay elevación real:

| Sombra | Cuándo |
|---|---|
| `shadow-sm` | Cards interactivas en hover |
| `shadow-md` | Dropdowns, popovers |
| `shadow-lg` | Modales, drawers |

Cards estáticas: **sin sombra**, solo `border border-border`.

---

## 6. Motion

| Propiedad | Duración | Easing | Cuándo |
|---|---|---|---|
| Hover (color/bg) | 200ms | ease-out | Botones, links, nav |
| Focus | 150ms | ease-out | Anillo de foco |
| Entrada modal | 200ms | ease-out | transform + opacity |

**Reglas:**
- Solo animar `transform` y `opacity` para animaciones de entrada/salida.
- Hover de color: `transition-colors duration-200`.
- Siempre incluir `motion-reduce:transition-none` o `motion-reduce:animate-none`.
- No animar layout (width, height, margin, padding).

---

## 7. Catálogo de componentes

### Botones

```html
<!-- Primario: una acción principal por viewport -->
<button class="btn-primary">Guardar</button>

<!-- Secundario: acciones neutras -->
<button class="btn-secondary">Cancelar</button>

<!-- Ghost: acciones de baja jerarquía (toolbar, sidebar) -->
<button class="btn-ghost">Cerrar sesión</button>

<!-- Danger: destructivo (anular, desactivar) -->
<button class="btn-danger">Anular venta</button>
```

**No usar** `btn-primary` para múltiples acciones en la misma vista.

### Tarjetas

```html
<div class="card">Contenido</div>
<div class="card p-6">Card amplia (settings)</div>
```

**No usar** `card` para agrupar elementos que no necesitan borde/fondo.

### Formularios

```html
<label class="form-label">Nombre</label>
<input class="input" type="text">
<p class="form-help">Texto de ayuda</p>
<p class="form-error" role="alert">Error de validación</p>
```

`input` ya incluye focus ring. No añadir `focus-visible:` manual.

### Tablas

```html
<div class="overflow-x-auto rounded border border-border bg-surface">
  <table class="table">
    <thead>
      <tr class="table-header">
        <th class="px-4 py-2 text-start font-medium">Columna</th>
      </tr>
    </thead>
    <tbody>
      <tr class="table-row">
        <td class="px-4 py-2">Dato</td>
      </tr>
    </tbody>
  </table>
</div>
```

### Badges de estado

```html
<span class="badge badge-success">Activo</span>
<span class="badge badge-warning">Parcial</span>
<span class="badge badge-danger">Cancelada</span>
```

### Alertas

```html
<div class="alert alert-success" role="alert">Venta registrada.</div>
<div class="alert alert-warning" role="alert">Tasa USD desactualizada.</div>
<div class="alert alert-danger" role="alert">Error al guardar.</div>
```

> `alert-warning` usa `text-foreground` en vez de `text-warning` porque
> amber-700 sobre amber-50 no alcanza contraste AA (4.5:1). Los otros dos
> sí lo cumplen con su color.

### Modal

```html
<div class="modal-overlay">
  <div class="modal-panel">
    <h2 class="heading-2">Título</h2>
    <p class="muted-text">Contenido.</p>
  </div>
</div>
```

### Enlaces

```html
<a href="..." class="link">Ver detalle</a>
```

`link` incluye focus ring. No añadir `focus-visible:` manual.

### Otros

```html
<p class="muted-text">Descripción secundaria.</p>
<p class="small-text">Última actualización: hace 2h.</p>
<div class="skeleton h-4 w-32"></div>
<hr class="divider">
```

---

## 8. Reglas duras

1. **No introducir colores fuera de los tokens.** Usar utilities como `bg-brand`, `text-danger`, `border-border`. Nada de `bg-blue-500` o `text-gray-700`.
2. **No usar `style=` inline.** Todo va por clases.
3. **No usar `dark:`.** Dark mode está fuera de alcance.
4. **No usar iconos de librería.** SVG inline estilo Heroicons (stroke-width: 1.5, 20×20 o 24×24).
5. **Todo focus visible con ring brand.** Nunca quitar `outline` sin reemplazo. Los componentes ya lo incluyen.
6. **Animar solo transform y opacity.** Hover de color: `transition-colors duration-200` es aceptable.
7. **Máximo una acción primaria por viewport.** Si hay dos, una es `btn-secondary`.
8. **Convención en `@layer components`: `@apply`.** El spec previo (SPEC-sidebar-navegacion) dijo "CSS plano, sin @apply", pero las 8 clases existentes usaron `@apply` desde su creación. Se mantiene `@apply` por consistencia interna: una sola convención en el archivo. Razón: `@apply` compila a las mismas utilities que Tailwind genera, mantiene el DRY con el sistema de utilities, y evita divergencia entre lo que el sistema produce y lo que se escribe a mano. Si alguna fase necesita CSS plano (p. ej. para `@media` anidados o selectores complejos), escribirlo en CSS plano **fuera** de `@layer components`, como ya hace el sidebar.
9. **`--color-brand` NO se cambia.** Sidebar y tests dependen de él.
10. **Tokens sin consumidor = deuda.** No definir un token en `@theme` sin al menos una clase o template que lo use.

---

## 9. Do / Don't

### Do: usar clases semánticas

```html
<!-- SÍ -->
<h1 class="heading-1">Clientes</h1>
<button class="btn-primary">Nuevo cliente</button>
<span class="badge badge-success">Activo</span>
```

### Don't: duplicar utilidades inline

```html
<!-- NO -->
<h1 class="text-2xl font-semibold text-foreground">Clientes</h1>
<button class="rounded bg-brand px-4 py-2 font-medium text-white hover:bg-brand/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2">Nuevo cliente</button>
```

### Do: focus ring en componentes

```html
<!-- SÍ: input ya incluye focus ring -->
<input class="input" type="text">
```

### Don't: repetir focus ring manualmente

```html
<!-- NO -->
<input class="rounded border border-border bg-surface px-3 py-2 text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand" type="text">
```

### Do: sombra sutil solo donde hay elevación

```html
<!-- SÍ: modal con sombra -->
<div class="modal-panel">...</div>
```

### Don't: sombra en cards estáticas

```html
<!-- NO -->
<div class="card shadow-lg">...</div>
```
