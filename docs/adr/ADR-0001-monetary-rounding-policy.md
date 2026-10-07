# ADR-0001: Política de Redondeo Monetario

## Contexto
Todo cálculo monetario en el sistema debe usar `bcmath` exclusivamente. Nunca `float`, `round()`, ni `number_format()` para lógica de negocio.

## Decisión
La clase `App\Service\Money\Money` implementa un value object inmutable con:
- **Redondeo**: `ROUND_HALF_UP` (estándar contable "redondeo comercial")
- **Escala**: Configurable por tenant vía `SettingsService::getCurrencyScale()` (por defecto 2 decimales)
- **Operaciones**: `add`, `sub`, `mul`, `div` devuelven nueva instancia redondeada a la escala
- **Comparación**: `compare`, `isZero`, `isNegative`, `isPositive` usan `bccomp` con la escala

## Justificación de no usar librerías externas
Se evaluaron `moneyphp/money` y `brick/money`:
1. **Dependencia innecesaria**: Nuestro dominio solo necesita aritmética decimal con escala fija, sin intercambio de divisas, sin parsing de locale, sin formateo complejo.
2. **Validación ISO 4217**: `moneyphp/money` valida códigos de moneda contra lista ISO; nuestro `baseCurrency` es configurable por tenant (ej. "ARS", "USD", "COP") y no debe fallar por códigos no estándar.
3. **Superficie de API**: `brick/money` añade ~50 clases; ratio valor/complejidad no justifica la dependencia para 8 operaciones básicas.
4. **Control de redondeo**: Configurar `RoundingMode` y `Scale` en librerías externas es más verboso que `bcdiv($a, $b, $scale)` directo.
5. **Migración futura**: Si se necesita multi-moneda real (FX, conversión), se puede migrar a `brick/money` sin romper la interfaz pública de `Money`.

## Consecuencias
- Positiva: Control total, cero dependencias, rendimiento predecible, fácil de auditar.
- Negativa: Requiere disciplina para no mezclar `float` en ningún servicio. Revisión de código obligatoria.

## Estado
Aceptado. Implementado en `App\Service\Money\Money`.

## Referencias
- `App\Service\SettingsService::getCurrencyScale()`
- `App\Service\Money\Money::normalizeAmount()` (punto único de redondeo)