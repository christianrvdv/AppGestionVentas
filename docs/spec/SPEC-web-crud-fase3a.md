# Spec: Web Productos y Clientes (Fase 3a)

## Objetivo
CRUD web de productos y clientes sobre entidades ya existentes: listado paginado
con filtro `?q=`, creación, edición y desactivación. Activar los nav links de
Productos y Clientes.

Usuario: operador de negocio autenticado (ROLE_USER). Éxito: crea un producto con
SKU y margen, lo edita, lo desactiva; crea un cliente con teléfono/email y el
sistema rechaza duplicados **dentro del mismo negocio** pero permite el mismo
dato en otro negocio; ambos listados pagina, filtra y no muestra datos de otro
tenant.

## Fuera de alcance (no tocar)
- Imagen de producto; categorías como entidad separada; importación/exportación;
  fusión de clientes duplicados; refactor de los CRUD de inversiones.
- Ventas, Reportes, Settings, tasas.
- Migraciones: los campos ya existen en el esquema (`doctrine:schema:validate`
  debe seguir OK sin cambios).
- Borrado físico: la única baja es `isActive = false` (los productos cuelgan de
  `InvestmentItem` con `RESTRICT`; los clientes de `Sale`/`Payment`).

## Decisiones fijadas
- **Rutas**: `GET /productos` (`app_product_index`), `GET|POST /productos/nuevo`
  (`app_product_new`), `GET|POST /productos/{id}/editar` (`app_product_edit`),
  `POST /productos/{id}/desactivar` (`app_product_deactivate`). Ídem con
  `/clientes/nueva` (`app_customer_*`). El filtro es query param `?q=`, nunca
  path param (patrón de Fase 2).
- **Paginación**: `knplabs/knp-paginator-bundle`, 10 por página,
  `createPaginatedQueryBuilder(int $tenantId, ?string $q): QueryBuilder`
  (aditivo en el puerto, igual que Fase 2), orden `name ASC, id ASC` (id como
  desempate para que la paginación sea estable). Knp conserva los query params
  actuales en los links (SlidingPaginationSubscriber copia
  `$request->query->all()`), así que `?q=` sobrevive a la página 2 sin código
  extra.
- **Unicidad por tenant**: `#[UniqueEntity]` sobre la entidad, con `tenant`
  incluido en `fields` y `ignoreNull` activo por defecto:
  `#[UniqueEntity(fields: ['sku', 'tenant'], ...)]`. Si el campo es `null` el
  validador se salta la comprobación (varios SKU/teléfono/email nulos están
  permitidos, igual que en PostgreSQL). La entidad lleva el invariante porque
  la restricción de BD ya está ahí (`uniq_product_tenant_sku`,
  `uniq_customer_tenant_phone`, `uniq_customer_tenant_email`). Ver ADR-0014.
- **Tenant en el form**: el controller crea la entidad y le hace
  `setTenant($user->getTenant())` **antes** de `createForm()`; `tenant` no es
  campo del form, así que el validador siempre tiene el tenant del negocio
  actual y un form no puede escribir el de otro. En edición la entidad ya viene
  de `findByIdAndTenant()`.
- **Margen 0-100**: `NumberType` con `input => 'string'` (la propiedad es
  `?string` DECIMAL) + `scale: 2` + `html5`, y `Range(min: 0, max: 100)`.
  `RangeValidator` acepta strings numéricos y distingue "no numérico" de
  "fuera de rango".
- **Desactivar**: `POST` con `FormType` + CSRF (mismo patrón que
  `app_investment_confirm`), botón en la fila del listado solo si
  `isActive`. Reactivar = editar y marcar "Producto activo".
- **Turbo Frames: NO se usan.** SPEC-web-inversiones-fase2.md los excluyó
  explícitamente, así que no hay ese patrón en Fase 2 que reusar; además el
  flash vive fuera del frame en `base.html.twig`, por lo que una acción dentro
  de un frame perdería el mensaje. Navegación de página completa, como Fase 2.
- Roles: `ROLE_USER` (igual que inversiones); el `access_control` de
  `security.yaml` ya cubre `^/`.

## Alcance y criterios de aceptación

### 1. Repositorios (aditivos)
- [ ] `ProductRepositoryInterface`/`CustomerRepositoryInterface` +
      `createPaginatedQueryBuilder(tenantId, ?q)`; filtra por tenant de forma
      explícita (no depende del SQLFilter) y por nombre/SKU (productos) o
      nombre/teléfono/email (clientes) con `LOWER(...) LIKE :term`.
- [ ] Ningún método existente cambia de firma.

### 2. Listados `/productos` y `/clientes` (GET)
- [ ] Tabla con paginación `knp_pagination_render` dentro de `nav` con
      `aria-label`; filtro `?q=` en un `method="get"` (trim + tope de 100
      caracteres, no string ⇒ sin filtro).
- [ ] Vacío: mensaje amigable + link a crear el primero. Filtro sin resultados:
      mensaje distinto.
- [ ] Botón "Nuevo producto"/"Nuevo cliente" y, por fila, "Editar" + "Desactivar"
      (POST con CSRF, solo si está activo).
- [ ] Solo muestra entidades del tenant de la sesión (test con tenant ajeno).

### 3. Crear `/productos/nueva` y `/clientes/nueva` (GET/POST)
- [ ] Campos: producto `name, sku, category, defaultMarginPct, isActive`;
      cliente `name, phone, email, address, notes, isActive`.
- [ ] POST válido → persist + flush + flash + redirect al listado (302).
      POST inválido → re-render con errores de campo (200), sin persistir.
- [ ] Validación: `name` requerido; longitudes según columna (150/50/80 para
      producto; 150/30/180/255 para cliente); email con `Email`; margen
      numérico 0-100.
- [ ] SKU duplicado en el mismo tenant → error de campo, no persiste.
- [ ] Teléfono o email duplicado en el mismo tenant (si están seteados) → error
      de campo, no persiste.
- [ ] Mismo SKU/teléfono/email en **otro** tenant → se crea sin error.

### 4. Editar `/productos/{id}/editar` (GET/POST)
- [ ] Carga por `findByIdAndTenant` (404 si no existe o es de otro tenant).
- [ ] POST válido → flush + flash + redirect. Editar sin tocar el SKU propio no
      dispara el error de unicidad.
- [ ] El form incluye `isActive` (reactivación).

### 5. Desactivar (POST)
- [ ] `isActive = false` + flush + flash + redirect al listado; token CSRF
      inválido → flash de error, sin cambios.

### 6. Navegación
- [ ] `base.html.twig`: "Productos" y "Clientes" pasan de placeholder a link
      real con `aria-current`.

## Comandos
- Tests: `php bin\phpunit` (suite completa verde).
- Lint + static: `php bin\phpstan` (src nivel 6 / tests nivel 5), y
  `composer ci` completo.
- Esquema: `php bin\console doctrine:schema:validate` (sin cambios).

## Estrategia de tests
Functional (WebTestCase + `FunctionalTestCase`, datos sembrados a mano):
- Product: requiere login; pagina con 12 productos; filtra por nombre y por
  SKU; aísla tenants; crear válido persiste con tenant y `isActive`; crear con
  margen 150 no persiste; SKU duplicado en el tenant no persiste; mismo SKU en
  otro tenant sí persiste; editar actualiza; desactivar pone `isActive=false`.
- Customer: requiere login; pagina; filtra por nombre/teléfono/email; aísla
  tenants; crear válido persiste; teléfono duplicado en el tenant no persiste;
  email duplicado en el tenant no persiste; tel/email nulos repetidos sí
  persisten; editar actualiza; desactivar.

## Límites
- Siempre: tests antes de dar por hecho; textos en español; contraste AA; CSRF
  en todo POST; sin lógica de negocio en Twig.
- Preguntar antes: tocar entidades/repos del dominio más allá de las adiciones
  aditivas listadas; migraciones.
- Nunca: secrets commiteados; borrar tests existentes; exponer datos de otro
  tenant (siempre `findByIdAndTenant` / `TenantContext`).

## Criterios de aceptación globales (DoD)
Listados paginados y filtrables; CRUD con validación de unicidad por tenant;
smoke tests de create/edit/list verdes; `composer ci` verde;
`doctrine:schema:validate` OK; un commit
`feat(web): CRUD de productos y clientes con paginación (Fase 3a)`.

## Desvíos documentados (Fase 3a)

Se implementó todo el alcance, con cuatro desvíos respecto del plan previo.
Todos se resolvieron hacia la opción más pequeña y se documentan aquí y en el
código:

1. **Sin Turbo Frames.** Turbo está en el importmap (viene con la receta de
   Symfony UX), pero el CRUD no lo usa: cada navegación es un reload completo
   y los flashes viven en `base.html.twig`. Un Turbo Frame por tabla obliga a
   definir qué pasa con el flash, con el scroll y con el botón "atrás" del
   navegador; eso es una decisión de UX, no un detalle de implementación, y
   queda para cuando se diseñe. La consecuencia práctica es que el HTML de
   los listados es el mismo con o sin Turbo.
2. **CSRF de "desactivar" con `isCsrfTokenValid('submit', ...)` y no con un
   `createForm(FormType::class)`** como hace `InvestmentController`. El token
   id `submit` es *stateless* (`config/packages/csrf.yaml`), así que un único
   `csrf_token('submit')` sirve para todas las filas del listado. Un
   `FormType` vacío esperaría un `form[_token]` namespaced por fila, que es
   un nombre duplicado en el mismo DOM. Mismo token id, otro mecanismo de
   envío; el token inválido sigue dando el flash "Solicitud inválida."
3. **`empty_data => null` en todos los campos opcionales de texto** (SKU,
   categoría, teléfono, email, dirección, notas). Sin eso, un campo vacío se
   guarda como `''` y no como `NULL`, y dos clientes sin teléfono chocan en
   `uniq_customer_tenant_phone` aunque `ignoreNull` esté en `true`. El índice
   de BD solo salta los `NULL`, no los `''`.
4. **El tenant no se chequea a mano en el controller** (paso original del
   plan: `findBySku()` + `409`): ya lo resuelve `#[UniqueEntity]` con `tenant`
   en `fields`. Ver `docs/adr/ADR-0014-unicidad-por-tenant.md`.

### Hallazgos fuera de alcance (solo se reportan)

- **`tests/Factory/` se eliminó** (9 fábricas Foundry). Eran código muerto:
  ADR-0013 las reescribió a la API 2.x y ninguna se usaba fuera de ese
  directorio. El paquete `zenstruck/foundry` sigue instalado; sacarlo es una
  decisión aparte.
- **`?TEMPoriginal_listener.php` en la raíz del repo**: un archivo temporal
  de un listener previo, con `?` en el nombre, que no debería estar en
  control de versiones. No se toca en esta fase.
- El `TenantFilter` de Doctrine sigue habilitado sobre el `EntityManager`
  compartido después de una request, **incluidos los tests**: una aserción
  que usa `findAll()` sobre una entidad filtrable ve solo el tenant de la
  sesión de prueba. Las aserciones de esta fase se hacen con
  `findBy(['tenant' => $tenant])` a propósito.

## Preguntas abiertas
Ninguna.
