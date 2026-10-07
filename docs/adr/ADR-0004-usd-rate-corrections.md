# ADR-0004: Manejo de Correcciones de Tasa de Dólar

## Contexto
Los usuarios pueden registrar una tasa USD para una fecha. Si se equivocan y vuelven a ingresar una tasa para la misma fecha, no debemos sobrescribir la anterior (perderíamos auditoría). Tampoco debemos permitir dos tasas "vigentes" simultáneas para la misma fecha.

## Decisión
Política de **corrección encadenada**:
1. Al llamar `UsdRateService::setRate($date, $rate)`, se busca tasa efectiva existente para esa fecha (`findEffectiveByDate`).
2. Si **no existe**: se crea `UsdRate` normal (`isCorrection = false`, `supersedes = null`).
3. Si **existe**: se crea `UsdRate` con `isCorrection = true` y `supersedes = $existing`.
4. Consultas `getCurrent()` y `getEffectiveFor($date)` devuelven **la última insertada** (ORDER BY createdAt DESC, id DESC), que será la corrección más reciente.
5. El setting `KEY_USD_RATE_CURRENT` se actualiza siempre al valor vigente (corrección incluida).

## Consecuencias
- Historial completo preservado: se sabe qué tasa había, quién la corrigió, cuándo y por qué (sourceNote).
- Consultas deterministas: siempre hay una sola tasa "vigente" por fecha.
- Migración sencilla: si en el futuro se quiere "anular corrección", basta con crear otra corrección que apunte a la original.

## Alternativas descartadas
- **Sobrescribir**: Pierde auditoría, no se sabe valor anterior.
- **Única por fecha (unique constraint)**: Obliga a borrar antes de corregir; pierde historial.
- **Ventana de vigencia (from/to)**: Complejidad innecesaria para correcciones puntuales.

## Estado
Aceptado. Implementado en `App\Service\UsdRateService`.

## Referencias
- `App\Entity\UsdRate::$isCorrection`, `::$supersedes`
- `App\Repository\UsdRateRepository::findEffectiveByDate()` (ORDEN BY createdAt DESC, id DESC)