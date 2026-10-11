# ADR-0017: Política de flush y recompute de InvestmentSummary

- **Estado:** Aceptado (Fase 3d, Paso 0).
- **Fecha:** 2026-10-11.
- **Contexto:** preámbulo de Fase 3d (Pagos en UI). Antes de tocar la UI de
  pagos conviene cerrar una invariante que afecta a todo lo que muestre
  "recuperado / pendiente": quién hace el `flush()` cuando un servicio
  recalcula `InvestmentSummary`.

## Problema

`InvestmentSummaryService::recompute()` hace dos cosas a la vez, y ambas
dependen de que los datos ya estén en la base:

1. **Lee con agregados SQL/DQL**: `getStockByInvestment`,
   `getLostQuantityByInvestment`, `sumGainLossByInvestment`,
   `sumCostRecoveredByInvestment`. Doctrine **no** autocommitea antes de
   una consulta, así que un cambio que esté solo en la Unit of Work es
   invisible para esas lecturas.
2. **Escribe en una entidad ya existente**: muta `InvestmentSummary` (o
   crea una si no hay, sin `persist()`). Un setter sobre una entidad
   persistida **no** llega a la base hasta el siguiente `flush()`.

Eso produce dos fallos distintos con la misma forma:

| | Síntoma | Quién lo paga |
| --- | --- | --- |
| **Sin flush previo** | `recompute()` calcula con datos viejos | el propio servicio, antes de leer |
| **Sin flush final** | `recompute()` calcula bien pero nadie guarda; la siguiente request lee la fila anterior | el propio servicio, después de escribir |

Symfony no hace `flush()` automático al final de la petición: lo que no
flushea quien mutó, no existe para nadie más.

### Hallazgos de la auditoría

Servicios que llaman a `recompute()`:

| Servicio | flush previo | flush final | Estado |
| --- | --- | --- | --- |
| `SaleService::register` | sí (flush #2, antes de línea 163) | sí (flush #3) | ya correcto |
| `InvestmentRevaluationService::revalue` | sí | sí | ya correcto |
| `SaleVoidService::void` | sí (intermedio) | **no** | **corregido aquí** |
| `LossService::register` | **no** | **no** | **corregido aquí** |
| `InvestmentConfirmationService::confirm` | n/a | llamador | correcto: no llama a `recompute()`, crea el summary con `persist()` y el llamador flushea |

`LossService` era un bug latente (sin llamadores todavía, pero figura como
implementado en ADR-0003): registraba la merma, no flusheaba, y después
`recompute()` leía `unitsLost = 0` y `unitsRemaining` con el stock
anterior. `SaleVoidService` sí flusheaba antes de recalcular, pero dejaba
el summary recién calculado y el `setStatus()` de la inversión solo en
memoria: anular una venta sin un `flush()` del llamador dejaba la venta
"vendida" y la inversión en `PARTIAL`.

Servicios que leen repositorio después de mutar entidades en memoria
(revisados, sin hallazgos):

- `UsdRateService::setRate`: flushea la tasa (línea 89) **antes** de leer
  el `AppSetting` (línea 95).
- `SaleService::preloadRemainingStock` y `sumCostRecoveredByInvestment`:
  se ejecutan antes de cualquier mutación persistible.
- `InvestmentCreationService`: `countByCodePrefix` antes de construir la
  inversión.
- `PaymentService::register`: solo lecturas; devuelve el `Payment` sin
  persistir y el llamador flushea.
- `InvestmentItemCalculatorService`: sin lecturas de BD; docblock explícito
  "NO PERSISTE: el llamador hace flush".
- Controllers (`Investment`, `Customer`, `Product`, `Sale`, `Onboarding`):
  todo `flush()` va seguido solo de `addFlash()` + `redirectToRoute()`;
  no hay lectura posterior en la misma acción.

Pendientes documentados (no corregidos aquí) — **cerrados en el cierre de
huecos de dominio previo a Fase 3e**:

- `ExpenseAllocationService::sumAllocatedByItem()` leía `sumByItem` de BD y
  **no tenía llamadores**: el hueco FUEL/FIX-4 ya estaba migrado a memoria en
  `InvestmentConfirmationService::validateExpenseAllocations()`. Eliminado
  junto con `ExpenseAllocationRepository::sumByItem()` y su firma en la
  interfaz: sin uso hoy, era código muerto. (Posteriormente se eliminó el
  repositorio completo — `ExpenseAllocationRepository` y
  `Contract\ExpenseAllocationRepositoryInterface` — al confirmar que no
  tenía ningún consumidor: el prorrateo vive entero en memoria, en
  `ExpenseAllocationService`.)
- `InvestmentSummaryService::recompute()` ante un summary inexistente crea
  `new InvestmentSummary()` **y ahora lo persiste, pero solo si la inversión
  está confirmada** (`$investment->isConfirmed()`). Un borrador no genera
  fila: `Investment` materializa `totalInvestment` solo al confirmar (el
  summary saldría en cero) y, sobre todo, `confirm()` crea su propio
  `InvestmentSummary`, que chocaría con el índice único
  `uniq_summary_investment`. Los cuatro callers actuales solo trabajan sobre
  inversiones confirmadas, así que el cambio es neutro hoy; lo que cambia es
  que un caller nuevo ya no puede "olvidar" el persist de un summary con
  datos reales. `recompute()` sigue **sin** flushear: el flush previo y final
  siguen siendo responsabilidad del caller (Decisión 1).

## Decisión

1. **El servicio que llama a `recompute()` es responsable del flush previo
   y del flush final.** El llamador no puede serlo: `SaleVoidService::void()`
   devuelve `void`, no hay señal de que haya trabajo pendiente, y el
   llamador no siempre es el controller (tests, comandos, servicios que se
   encadenan).
2. **Regla práctica:** todo lo que `recompute()` *lee con agregado SQL*
   exige un flush previo; todo lo que `recompute()` *escribe* exige un
   flush posterior.
3. **Un `flush()` extra en el llamador es redundante e inocuo**, pero no
   es la garantía. `SaleController::void()` conserva el suyo: es barato
   (no-op) y mantiene el controller robusto si el servicio cambia.
4. **Si el costo de flushear intermedio es alto, se lee en memoria** en
   lugar de flushear: es lo que ya hace FIX-4 con los allocations. Elegir
   una u otra es preferir memoria a transacción solo cuando el volumen lo
   justifica; no es una tercera vía para "olvidarse" del flush.

## Implementación

- `src/Service/Sale/SaleVoidService.php`: flush final después de
  `updateInvestmentState()`; docblock de política de flush reescrito.
- `src/Service/Inventory/LossService.php`: flush antes y después de
  `recompute()`; el `@return` pasa de "listo para persistir" a "ya
  persistido".
- `tests/Integration/Sale/SaleVoidFlushTest.php`: anula una venta **sin
  flush del lado del test** y hace `em->clear()` antes de leer; verifica
  summary e `Investment::status` en BD.
- `tests/Integration/Inventory/LossServiceFlushTest.php`: registra una
  merma con el mismo patrón; verifica `unitsLost`/`unitsRemaining`.

Los tests leen con `em->clear()` **siempre**. Es el detalle que hace que
la auditoría sea falsable: sin `clear()`, Doctrine devuelve la instancia
identificada con los valores en memoria y el test pasa aunque falte el
flush (fue exactamente por eso que el bug pasó desapercibido).

## Alternativas descartadas

| Alternativa | Por qué no |
| --- | --- |
| Dejar el `flush()` solo en el llamador (comportamiento previo) | Es el origen de ambos bugs: el llamador no sabe que hubo una recompute, y `void()` no devuelve nada con lo que señalarse. |
| Que `recompute()` haga su propio `flush()` | Un método llamado "recalcular" tendría efectos globales: rompería a `InvestmentConfirmationService` (persiste movements *después* del cálculo) y obligaría a los callers a aceptar un punto de transacción que no pidieron. |
| Listener `postFlush` que recalcula los summaries tocados | Magia implícita: la recompute pasaría a ejecutarse en un orden que nadie controla y en medio de otra transacción. |
| `em->flush()` automático al final de la request | Symfony no lo hace y no es un cambio que valga la pena introducir para un solo agregado. |
| Reordenar `recompute()` para leer después de escribir | Imposible: los agregados son SQL, y el SQL solo ve lo que está en la tabla. |

## Consecuencias

- **`LossService::register()` flushea dos veces.** Es caro (dos `UPDATE`
  en vez de uno) pero es la corrección directa; si las mermas se vuelven
  un camino frecuente, el siguiente paso es agrupar en una sola
  transacción, no eliminar el flush previo.
- **Un error entre la recompute y el flush final deja la mitad en
  memoria.** `SaleVoidService` ya validaba todo antes de mutar; el nuevo
  flush final es el único punto en el que puede fallar, y un fallo ahí
  revierte la transacción completa (no deja un summary a medias).
- **Los tests de integración deben leer con `em->clear()`** para afirmar
  algo sobre la base. Los que lean sin `clear()` solo prueban la Unit of
  Work, no la persistencia.
- Fija el patrón que va a seguir la Fase 3d: `PaymentService` no llama a
  `recompute()` (el pago no mueve la recuperación de la inversión), así
  que el controller de pagos solo necesita el `flush()` de siempre.

## Referencias

- `src/Service/Investment/InvestmentSummaryService.php` (`recompute()`)
- `src/Service/Sale/SaleService.php` (flush #1/#2/#3)
- `src/Service/Sale/SaleVoidService.php` (flush intermedio + final)
- `src/Service/Inventory/LossService.php`
- `src/Service/Investment/InvestmentRevaluationService.php`
- `src/Service/Investment/InvestmentConfirmationService.php` (FIX-4, lectura en memoria)
- `src/Repository/InventoryMovementRepository.php` (`getStockByInvestment`, `getLostQuantityByInvestment`)
- `tests/Integration/Sale/SaleVoidFlushTest.php`
- `tests/Integration/Inventory/LossServiceFlushTest.php`
- Depende de: `docs/adr/ADR-0003-idempotency-inventory-movements.md`,
  `docs/adr/ADR-0005-profit-recognition-modes.md`
