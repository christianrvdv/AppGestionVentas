# ADR-0005: Reconocimiento de Ganancia en Ambos Modos de Recuperación

## Contexto
Dos modos de recuperación de inversión:
- `PER_PRODUCT`: Cada producto recupera su costo y reconoce ganancia independientemente.
- `INVESTMENT_FIRST`: La ganancia solo se reconoce cuando las ventas acumuladas superan la inversión total.

## Decisión

### PER_PRODUCT (default)
```
recognizedProfitLine = grossProfitLine
```
Cada línea de venta reconoce su ganancia completa inmediatamente. No hay cruce entre productos.

### INVESTMENT_FIRST
```
acumuladoAntes = Σ costRecovered de líneas previas
acumuladoDespués = acumuladoAntes + costRecovered línea actual
exceso = max(0, acumuladoDespués - inversiónTotal)
recognizedProfitLine = min(exceso, grossProfitLine)
```
- Se trackea `accumulatedRecovered` secuencialmente por fecha de venta.
- Solo el exceso sobre la inversión total se reconoce como ganancia.
- Nunca se reconoce más que `grossProfitLine` (techo natural).
- Si `grossProfitLine < 0` (venta bajo costo), `recognizedProfitLine = 0` (no se reconoce pérdida).

### Invarianes (validados en SaleLine::applyRecognizedProfit)
1. `recognizedProfitLine >= 0` (nunca negativo)
2. `recognizedProfitLine <= max(0, grossProfitLine)` (techo = grossProfit si positivo, sino 0)
3. En modo INVESTMENT_FIRST, la suma de recognizedProfitLine de todas las líneas nunca excede el total de grossProfitLine positivo.

### Persistencia
- `SaleLine.recognizedProfitLine` guarda el valor calculado al momento de la venta.
- `InvestmentSummary` mantiene ambos totales:
  - `totalProfitPerProduct` = Σ recognizedProfitLine (modo PP)
  - `totalProfitInvestmentFirst` = Σ recognizedProfitLine (modo IF)
  - `syncActiveMode()` elige cuál exponer en `totalProfit` / `totalRecovered`.

### Reversión (anulación de venta)
- `SaleVoidService` pone `recognizedProfitLine = 0` en líneas anuladas.
- `InvestmentSummaryService::recompute()` recalcula desde cero.

## Alternativas descartadas
- **Reconocimiento proporcional en IF**: Repartir ganancia proporcionalmente a lo recuperado. Complejo y no refleja "primero recupera inversión".
- **Ganancia negativa en IF**: Permitir recognizedProfitLine < 0. Contable incorrecto: la pérdida vive en grossProfit, no en recognizedProfit.

## Estado
Aceptado. Implementado en:
- `App\Service\Sale\SaleService::calculateRecognizedProfit()`
- `App\Entity\SaleLine::applyRecognizedProfit()`
- `App\Service\Investment\InvestmentSummaryService::recompute()`

## Referencias
- ADR-0002 (sección "Manejo de ventas bajo costo" en SaleLine::applyRecognizedProfit)