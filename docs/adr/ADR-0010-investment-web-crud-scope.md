# ADR-0010: Alcance del CRUD web de inversiones (Fase 2)

Fecha: 2026-10-08
Estado: Aceptada

## Contexto

El dominio expone un flujo de inversión en varias etapas: creación de
borrador (`InvestmentCreationService::createDraft`), agregado de ítems
(`InvestmentItemService::addItem`, solo sobre inversiones no confirmadas),
prorrateo de gastos (`ExpenseAllocationService`), confirmación
(`InvestmentConfirmationService::confirm`, que materializa totales, crea
`InvestmentSummary` y movimientos de inventario PURCHASE) y transiciones de
estado (`InvestmentStateMachine`). La Fase 1 UI dejó el nav con placeholders
y el dashboard sin enlace de detalle; la spec de Fase 2 pide "CRUD de
inversiones: listado + detalle + crear/editar" sin detallar el shape de los
forms.

Opción tentadora: un único form con colecciones anidadas (cabecera + items +
expenses) y persistencia atómica. Eso requeriría mapear manualmente el
prorrateo, la generación de código y las validaciones del confirmador en un
Form Type, duplicando reglas de negocio ya probadas por 111 tests de dominio
y arriesgando invariantes (asignación de gastos, idempotencia de
movimientos).

## Decisión

El web CRUD reproduce las etapas del dominio en lugar de inventar un flujo
paralelo:

1. **Crear** genera solo la cabecera (fecha + descripción). Código, moneda
   base, tasa snapshot y estado OPEN los resuelve `createDraft()`.
2. **Ítems** se agregan desde el detalle con su propio form simple
   (producto, cantidad, costo unitario), delegando en `addItem()` — que ya
   valida tenant, estado y rangos, y dispara prorrateo + recálculo.
3. **Confirmar** y **cancelar** son acciones POST puntuales desde el
   detalle, delegando en `confirm()` y `cancel()`. La confirmación es
   necesaria en Fase 2: sin ella los totales permanecen en cero y el CRUD
   carece de valor de negocio.
4. **Editar** se limita a fecha y descripción del borrador (OPEN no
   confirmada). Totales, código y tasa no son editables desde la UI.
5. Gastos, pagos y cierre manual quedan **fuera de alcance**: se muestran
   en solo lectura si existen; su escritura es Fase 3.

## Consecuencias

- El controller queda delgado: orquesta forms, delega en servicios y captura
  sus excepciones como flashes (sin 500).
- Cero duplicación de reglas de negocio: una inversión creada por la UI es
  indistinguible de una creada por los tests de integración.
- La paginación se resuelve con un único método aditivo en
  `InvestmentRepositoryInterface` (`createPaginatedQueryBuilder`) que filtra
  explícitamente por tenant sin depender del SQLFilter.
- Fase 3 hereda la página de detalle: agregar ahí forms de gastos y pagos
  sin tocar la estructura de esta.
- Rate limiting de login se resuelve con la opción nativa
  `login_throttling` del SecurityBundle (5 intentos / 5 min) — sin código
  propio, verificada en vendor.
