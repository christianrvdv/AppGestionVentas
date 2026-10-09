# Plan: Fase 3a — CRUD de Productos y Clientes con paginación

Spec: `docs/spec/SPEC-web-crud-fase3a.md` · ADR-0014 · DoD: suite completa +
`composer ci` + `doctrine:schema:validate` + un commit
`feat(web): CRUD de productos y clientes con paginación (Fase 3a)`.

## Limpieza previa

0. **Fábricas Foundry** (`tests/Factory/`): verificado con grep que solo se
   referencian entre sí (ningún test las usa) → se eliminan. El paquete
   `zenstruck/foundry` y su recipe quedan instalados (fuera de alcance).

## Pasos

1. **Repositorios (aditivos)**: `createPaginatedQueryBuilder(int $tenantId,
   ?string $q): QueryBuilder` en `ProductRepositoryInterface` y
   `CustomerRepositoryInterface` + implementación (`andWhere` explícito sobre
   tenant, `LOWER(campo) LIKE :term` sobre nombre/SKU o
   nombre/teléfono/email, `ORDER BY name ASC, id ASC`).
2. **Unicidad por tenant**: `#[UniqueEntity(fields: ['sku'|'phone'|'email',
   'tenant'])]` en `Product`/`Customer` (ADR-0014). Sin cambios de esquema.
3. **Form types**: `ProductFormType` (name/sku/category/defaultMarginPct/
   isActive; margen `NumberType input:string scale:2` + `Range(0,100)`) y
   `CustomerFormType` (name/phone/email/address/notes/isActive; `Email`,
   longitudes por columna). `data_class` = entidad. Los 6 campos opcionales
   de texto llevan `empty_data => null`.
4. **ProductController** `#[Route('/productos')]`: index (paginate + `?q=`,
   truncado a 100), new (crea la entidad con
   `setTenant($user->getTenant())` **antes** de `createForm`), edit
   (`findByIdAndTenant`, 404 para lo ajeno), deactivate (POST + CSRF
   `isCsrfTokenValid('submit', ...)` + flash). Errores de unicidad = error de
   campo, no flash.
5. **CustomerController** `#[Route('/clientes')]`: mismo esquema.
6. **Templates**: `templates/product/{index,new,edit,_form}.html.twig` y
   `templates/customer/...`, reusando las clases de Fase 2; nav links reales
   en `base.html.twig` con `aria-current`.
7. **Tests TDD**: `tests/Functional/Product/{ProductIndexTest,ProductCrudTest}`
   y `tests/Functional/Customer/{CustomerIndexTest,CustomerCrudTest}` —
   paginado, filtro, aislamiento de tenant, create ok/inválido, unicidad por
   tenant (mismo tenant rechaza / otro tenant acepta), edit, deactivate,
   CSRF inválido.
8. **DoD**: suite completa; `composer ci`; `doctrine:schema:validate` sin
   cambios; code review; commit único.

## Checkpoints

- Post-1/2: `doctrine:schema:validate` sigue OK (no hay migraciones).
- Post-6: `/productos` y `/clientes` renderizan en dev con fixtures demo.
- Post-7: smoke tests de la fase verdes; suite completa verde.
- Post-8: `composer ci` verde y commit único.

## Riesgos

| Riesgo | Impacto | Mitigación |
| --- | --- | --- |
| `NumberType input:string` mapea mal el DECIMAL | Alto | Test de creación con margen 30.50 y lectura en BD |
| `UniqueEntity` con `tenant` sin inicializar | Alto | Controller setea tenant antes del form; test de crear |
| SKU/teléfono/email nulos repetidos rechazados | Medio | `ignoreNull` por defecto; test con dos nulos |
| Campo vacío guardado como `''` y no como `NULL` | Medio | `empty_data => null` en los 6 campos opcionales de texto |
| CSRF del desactivar por fila | Medio | Token id `submit` es stateless → un token plano sirve para todas las filas |
| Filtro `?q=` perdido en la paginación | Bajo | Knp copia `$request->query->all()`; test de página 2 |

## Resultados de verificación (2026-10-09)

- `php bin/phpunit` (fase): **35 tests / 150 assertions OK**
- `php bin/phpunit` (suite completa): **176 tests / 504 assertions OK**
- `composer ci`: container lint + Twig (16) + YAML (26) + PHPStan src nivel 6 +
  tests nivel 5 + PHPUnit → **todo verde**
- `doctrine:schema:validate`: mapping OK y esquema en sincronía (sin
  migraciones; solo se añadieron atributos de validación)
- Code review: **sin hallazgos críticos ni mayores**

Los cuatro desvíos respecto del plan previo (sin Turbo Frames; CSRF con
`isCsrfTokenValid('submit', ...)` en lugar de un `FormType` vacío;
`empty_data => null`; unicidad delegada en `#[UniqueEntity]` sin
`findBySku()` en el controller) están documentados en
`docs/spec/SPEC-web-crud-fase3a.md` § "Desvíos documentados" y en
`docs/adr/ADR-0014-unicidad-por-tenant.md`.
