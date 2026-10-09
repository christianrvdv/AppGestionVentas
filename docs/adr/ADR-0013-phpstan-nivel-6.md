# ADR-0013: Análisis estático con PHPStan (nivel 6 en src, nivel 5 en tests)

- **Estado:** Aceptado
- **Fecha:** 2026-10-09 (Fase 1.5c)
- **Contexto:** Fase 1.5 (cierre de deuda técnica antes de Fase 3)

## Problema

El proyecto no tenía análisis estático. Los problemas se descubrían (o no) en
runtime: dependencias inyectadas y nunca usadas, tipos ausentes en
repositorios genéricos, código muerto, aserciones triviales. Fase 1.5c pedía
levantar un piso de calidad antes de Fase 3 sin meter un refactor grande.

## Decisión

Instalar **PHPStan 2.3.1** (`phpstan/phpstan:^2.3`, dependencia de desarrollo)
y correr dos configuraciones:

| Config | Ámbito | Nivel | Por qué |
| --- | --- | --- | --- |
| `phpstan.neon` | `src/` | **6** | Nivel "missing types": el primer nivel que encuentra tipos ausentes y bugs reales, sin ensuciar el diff con reglas de estilo. |
| `phpstan.tests.neon` | `tests/` | **5** | Los tests usan fixtures y arrays mixtos; subirlos a 6 solo agrega ruido sin encontrar bugs. Se revisa en el futuro. |

Detalles:

- `phpstan-common.neon` guarda solo lo que ambos comparten (`tmpDir:
  var/phpstan`); cada config declara su propio `level` y sus `paths`.
- `bin/phpstan` corre ambas configuraciones, acepta argumentos extra y
  propaga el código de salida de cualquiera que falle.
- Scripts de Composer `lint`, `phpstan`, `test` y `ci`. No se agregó un
  `Makefile` porque `make` no está disponible en la máquina de desarrollo
  (Windows); los scripts de Composer son el equivalente cross-platform y ya
  estaban en uso para los `auto-scripts` de Symfony.
- **Sin baseline y sin `@phpstan-ignore`:** cada error se resuelve en su
  causa raíz (tipos, docblocks, eliminación de dependencias muertas). La
  única excepción está documentada abajo y tiene su `TODO`.
- `composer ci` = `lint` (container, twig, yaml + PHPStan) + `test`.

## Excepción documentada: `$id` de Doctrine

Las entidades exponen `getId()` sobre un atributo que Doctrine escribe por
reflection durante la hidratación; PHPStan nunca ve esa escritura y reporta
`property.onlyRead` (16 casos). Son falsos positivos de reflection, no código
muerto, y no se agrega un setter artificial solo para callar al analizador.

Queda un `ignoreErrors` con `identifier: property.onlyRead` y
`path: src/Entity` dentro de `phpstan.neon` (solo la config de `src/`, para
que la de tests no arrastre un ignore que nunca coincide), con el comentario
y el `TODO` correspondientes. Al instalar `phpstan/doctrine` ese ignore se
elimina: la extensión entiende el mapeo de identificadores de Doctrine.

## Resultado de la primera corrida (2026-10-09)

Total: **145 errores → 0**. Ambas configuraciones quedan en verde y `composer
ci` pasa.

### `src/` nivel 6: 55 errores → 0

| Categoría | Cantidad | Qué era |
| --- | --- | --- |
| `missingType.generics` | 20 | 16 repositorios genéricos sin `@extends ServiceEntityRepository<T>`, `AppUserProvider` sin `@implements UserProviderInterface<AppUser>`, 3 `Collection` sin tipo |
| `property.onlyRead` | 16 | los `$id` de Doctrine (excepción documentada arriba) |
| `missingType.iterableValue` | 13 | arrays y `list<>` sin tipo de elemento |
| `property.onlyWritten` | 5 | dependencias inyectadas en el constructor y nunca usadas: `$paymentRepository`/`$settingsService` en `PaymentService`, `$movementRepository` en `SaleVoidService`, `$allocationService` en `InvestmentConfirmationService`, `$calculatorService` en `InvestmentRevaluationService` |
| `nullCoalesce.offset` | 1 | `?? '0'` muerto en `Money::padScale()` |

### `tests/` nivel 5: 90 errores → 0

| Categoría | Cantidad | Qué era |
| --- | --- | --- |
| `staticMethod.notFound` | 48 | las fábricas llamaban a métodos estáticos que no existen en la clase base (`self::faker()`, `instantiate()`, `afterInstantiate()` como estáticos) |
| `class.notFound` | 18 | `Zenstruck\Foundry\Persistence\PersistentProxy`, tipo eliminado en Foundry 2.x |
| `class.extendsTrait` | 9 | las 9 fábricas hacían `extends Factories`, que en Foundry 2.9 es un **trait** → `Fatal error` al cargar la clase |
| `method.notFound` | 9 | incluye `Faker\Generator::dateTimeImmutableThisMonth()` en 3 fábricas: el método **no existe en Faker** (habría tirado `BadMethodCallException` en runtime) |
| `method.alreadyNarrowedType` | 5 | `assertNotNull()` sobre `int` y un `assertTrue(true)` |
| `function.alreadyNarrowedType` | 1 | `method_exists(Dotenv::class, 'bootEnv')` siempre cierto en `tests/bootstrap.php` |

Las fábricas se reescribieron a la API de Foundry 2.x: `extends
PersistentObjectFactory`, `class(): string`, `@extends
PersistentObjectFactory<T>`, y sin los overrides `create()`/`createOne()` de la
versión 1.x. **Ninguna fábrica se usa fuera de `tests/Factory/`**: eran código
muerto que igual había que corregir, porque cualquier test que la usara moría
en el `require`.

## Alternativa descartada: PHP CS Fixer en esta fase

Se instaló `friendsofphp/php-cs-fixer` con la receta de Symfony (reglas
`@Symfony`) y se corrió en modo seco: **112 de 137 archivos** se reescribirían,
incluyendo el renombrado de todos los métodos de test de `snake_case` a
`camelCase`, que choca con la convención vigente del proyecto. Se desinstaló.
Adoptarlo requiere un chat propio que decida primero la convención de nombres
de test; hasta entonces el estilo se mantiene a mano.

## Consecuencias

- `composer ci` es la puerta de entrada antes de cada commit.
- Un repositorio nuevo sin `@extends ServiceEntityRepository<Entidad>` rompe
  el nivel 6: el error aparece en el primer análisis.
- Una fábrica nueva debe extender `PersistentObjectFactory` (API 2.x), no el
  trait `Factories` (1.x).
- Mejoras futuras, en este orden: instalar `phpstan/doctrine` (borra el único
  `ignoreErrors` vigente) y subir `tests/` de nivel 5 a nivel 6.
- Habilitar la extensión `zip` de PHP (Laragon, `php.ini`) fue requisito para
  instalar paquetes con Composer; el `php.ini` previo quedó como
  `php.ini.bak-fase1.5c`.
