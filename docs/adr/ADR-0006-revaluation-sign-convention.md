# ADR-0006: Convención de Signo para Revaluación (Ganancia/Pérdida Latente)

## Contexto
Al cambiar la tasa USD, los costos en moneda local se revalúan. Se debe registrar ganancia/pérdida latente por el stock pendiente.

## Decisión
**Fórmula:**
```
revaluationGainLoss = quantitySnapshot * (newRealUnitCost - oldRealUnitCost)
```

**Convención de signo:**
- **Positivo (+)**: El costo unitario **subió** → **pérdida latente** (el inventario vale menos en términos reales, o costará más reponer).
- **Negativo (-)**: El costo unitario **bajó** → **ganancia latente** (el inventario vale más en términos reales, o costará menos reponer).

**Ejemplo:**
- oldRealUnitCost = 100, newRealUnitCost = 120, stock = 50
- diff = +20, gainLoss = 50 * 20 = **+1,000** (pérdida latente)

**Razón contable:**
- `realUnitCost` es un **costo** (egreso). Si sube, es peor para el negocio → pérdida.
- Consistente con: `totalRevaluationGainLoss` positivo reduce el profit en el summary.

**Persistencia:**
- `ItemCostRevaluation.revaluationGainLoss` guarda el valor con signo.
- `InvestmentSummary.totalRevaluationGainLoss` suma todos los ítems.
- `InvestmentSummary.totalInvestmentCurrent = totalInvestment + totalRevaluationGainLoss`
- `InvestmentSummary.totalPendingCurrent = totalInvestmentCurrent - totalRecovered`

**Impacto en P&L:**
- La ganancia/pérdida latente **no** pasa por P&L hasta que se vende el stock (realizada).
- Solo afecta métricas de "inversión actual" y "pendiente actual".

## Alternativas descartadas
- **Signo inverso** (positivo = ganancia): Confuso porque `realUnitCost` es costo, no ingreso.
- **No persistir gainLoss**: Obliga a recalcular siempre; loss de rendimiento en reportes.

## Estado
Aceptado. Implementado en:
- `App\Entity\ItemCostRevaluation::recalculateGainLoss()`
- `App\Service\Investment\InvestmentRevaluationService::revalueItem()`
- `App\Service\Investment\InvestmentSummaryService::recompute()`

## Referencias
- `App\Entity\ItemCostRevaluation::$revaluationGainLoss` (comentario en entidad)