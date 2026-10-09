# ADR-0014: Unicidad de SKU, teléfono y email por tenant

- **Estado:** Aceptado
- **Fecha:** 2026-10-09 (Fase 3a)
- **Contexto:** Fase 3a (CRUD web de Productos y Clientes)

## Problema

Tres campos son "únicos dentro de un negocio", no en todo el sistema:

| Entidad | Campo | Índice en BD |
| --- | --- | --- |
| `Product` | `sku` | `uniq_product_tenant_sku (tenant_id, sku)` |
| `Customer` | `phone` | `uniq_customer_tenant_phone (tenant_id, phone)` |
| `Customer` | `email` | `uniq_customer_tenant_email (tenant_id, email)` |

La restricción ya existe en PostgreSQL, pero llegar a ella significa que el
formulario devuelve un `SQLSTATE 23505` y un 500 al usuario. Hace falta validar
en la aplicación, con el mensaje en español y el error apuntando al campo
correcto, y sin que el check se pueda engañar desde el request.

## Decisión

`#[UniqueEntity]` sobre la **entidad**, con `tenant` dentro de `fields`:

```php
#[UniqueEntity(
    fields: ['sku', 'tenant'],
    errorPath: 'sku',
    message: 'El SKU {{ value }} ya está en uso en este negocio.',
)]
```

Detalles que no son menores:

- **`tenant` va en `fields`, no en `criteria` de un repositorio.** El validador
  arma el `findBy()` por los propios campos del objeto, así que el tenant que
  se compara es el que la entidad ya tiene en memoria.
- **El tenant nunca sale del request.** El controller hace
  `setTenant($user->getTenant())` **antes** de `createForm()` y `tenant` no es
  campo del form. En edición la entidad ya viene de `findByIdAndTenant()`. Un
  form no puede escribir el tenant de otro negocio porque no tiene dónde.
- **`ignoreNull` se deja en `true`** (el valor por defecto): dos productos sin
  SKU conviven, igual que en PostgreSQL, donde `NULL != NULL` en un índice
  único. Ponerlo en `false` haría que el validador rechazara lo que la BD
  acepta.
- **`errorPath` apunta al campo del form**, no a la entidad: `sku`, `phone`,
  `email`. El error se pinta debajo del input que hay que corregir.
- `Customer` lleva **dos atributos** (uno por campo), cada uno con su
  `errorPath` y su mensaje. Un solo atributo con `fields: ['phone', 'email',
  'tenant']` exigiría que los dos coincidieran a la vez, que es lo contrario
  de lo que se quiere.
- **El mensaje usa `{{ value }}` sin comillas propias.** El validador
  formatea el parámetro entre comillas (pretty print de strings), así que
  `El SKU "{{ value }}" ...` renderiza `El SKU ""CAFE-1"" ...`. La comilla
  la pone el framework.

## Sin `findBySku()` en el controller

El plan original contemplaba un chequeo a mano en el controller antes del
submit. Se descarta porque duplica la regla que la entidad ya lleva, no
compone con el resto de las validaciones del form (hay que decidir en qué
orden se acumulan los errores) y deja una ventana de carrera entre el `SELECT`
y el `INSERT`. Con `#[UniqueEntity]` la regla vive en un solo sitio.

## Alternativas descartadas

| Alternativa | Por qué no |
| --- | --- |
| Índice único de BD y nada más | El usuario ve un 500 en lugar de un error en el campo. |
| `UniqueEntity` global (sin `tenant`) | Rompe el modelo multi-tenant: dos negocios no pueden vender el mismo SKU. |
| Un `UniqueEntity` para `Customer` con `fields: ['phone','email','tenant']` | Exige que coincidan teléfono y email a la vez. |
| Validación en un listener de Doctrine / en el repository | Se ejecuta tarde (tras el form) o queda fuera del ciclo de vida del form. |

## Consecuencias

- **Queda una carrera residual** entre el chequeo del validador y el índice
  de BD (TOCTOU). Es aceptada a propósito: el índice sigue siendo la fuente de
  verdad y el caso (dos usuarios del mismo negocio con el mismo SKU en los
  mismos milisegundos) devuelve un 500 raro, no corrompe datos.
- **Editar sin tocar el valor propio no dispara el error**: el validador
  excluye por identidad de objeto la entidad que se está validando.
- **`ignoreNull: true` no se puede "optimizar" a `false`** sin volver a
  revisar la semántica de NULL de los índices de BD.
- El mismo patrón sirve para el próximo campo con esta restricción: ponerlo en
  `fields` junto a `tenant`, nunca como unicidad global.
