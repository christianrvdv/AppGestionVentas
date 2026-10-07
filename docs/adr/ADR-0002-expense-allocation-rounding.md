# ADR-0002: Política de Prorrateo de Gastos y Manejo de Remanentes

## Contexto
Cada `InvestmentExpense` con `isAllocated = true` debe distribuirse entre los `InvestmentItem` de la inversión proporcionalmente al valor de mercancía de cada ítem (`unitCost * quantity`). La suma de las asignaciones debe cuadrar **exactamente** con el monto del gasto.

## Decisión
**Algoritmo "último ítem absorbe remanente":**
1. Ordena ítems por ID (determinismo).
2. Para cada ítem excepto el último:
   - `allocationPct = (itemSubtotal / totalMerchandise) * 100`
   - `allocatedAmount = round(expenseAmount * allocationPct / 100, scale)`
   - Acumula `allocatedSum += allocatedAmount`
3. Para el **último ítem**:
   - `allocatedAmount = expenseAmount - allocatedSum` (diferencia exacta, sin redondeo adicional)
   - `allocationPct` se calcula igual para consistencia, pero no se usa para el monto.

**Propiedades:**
- Σ allocatedAmount === expenseAmount **exactamente** (sin centavos de diferencia).
- El error de redondeo (máximo ±0.01 por ítem) se concentra en el último ítem.
- Determinista: mismo resultado en cada ejecución (orden por ID).

**Caso borde: totalMerchandise = 0**
- No hay base para prorratear.
- Se borran allocations existentes de gastos prorrateables.
- `allocatedExpense` de ítems se pone a 0.

## IDEMPOTENCIA
`allocateForInvestment()` es idempotente:
- `deleteByExpense()` antes de recrear allocations del gasto.
- `deleteByItem()` + recálculo suma al actualizar `allocatedExpense` en ítems.

## ESCALA
Redondeo a la escala de la moneda del tenant (`SettingsService::getCurrencyScale()`, default 2).
Usa `bcmath` con `ROUND_HALF_UP` (ver ADR-0001).

## ALTERNATIVAS DESCARTADAS
- **Redondeo bancario (ROUND_HALF_EVEN)**: No es estándar contable en la región.
- **Distribuir remanente al azar**: No determinista, rompe tests.
- **Distribuir remanente al mayor ítem**: Requiere ordenar por subtotal; cambia si hay empates.
- **Guardar diferencia en cuenta "diferencia de cambio"**: Complejidad innecesaria para centavos.

## ESTADO
Aceptado. Implementado en `App\Service\ExpenseAllocationService`.

## REFERENCIAS
- `App\Service\ExpenseAllocationService::allocateExpense()`
- `App\Repository\Contract\ExpenseAllocationRepositoryInterface::deleteByExpense()`, `::deleteByItem()`, `::sumByItem()`