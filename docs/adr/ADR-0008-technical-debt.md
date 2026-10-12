# ADR-0008: Deuda Técnica Pendiente

## Contexto
Durante la implementación de la capa de servicios se identificaron decisiones que requieren seguimiento futuro (migraciones, tests, ajustes de esquema, wiring de dependencias).

## Ítems pendientes (actualizado en cierre post-Fase 3d)

### 1. Inyección de repositorios faltantes en servicios ✅ **Resuelto en Fase 3d**
Todos los servicios inyectan sus repositorios vía autowiring + `#[AsAlias]`. No hay wiring manual en `services.yaml` necesario.

### 2. Método `InvestmentRepositoryInterface::countByCodePrefix` ✅ **Resuelto**
Añadido a interfaz e implementación. Tests de creación de inversión (`InvestmentCrudTest`) lo cubren.

### 3. Relación `Investment <-> InvestmentSummary` ✅ **Resuelto**
**Antes:** Unidireccional (`InvestmentSummary -> Investment`).
**Actual:** Bidireccional — `Investment::$summary` (`#[ORM\OneToOne(mappedBy: 'investment', ...)]`) permite navegar con `$investment->getSummary()`.

### 4. Tests unitarios e integración 🟡 **Mayormente resuelto**
**Existen y pasan:**
- `Money` ✅
- `ExpenseAllocationService` ✅
- `InvestmentConfirmationService` ✅
- `InvestmentRevaluationService` ✅ (nuevo desde la auditoría)
- `SaleService` ✅
- `SaleVoidService` ✅
- `LossService` ✅
- `PaymentService` ✅
- `RecoveryRecognitionService` ✅
- `InvestmentStateMachine` ✅

**Falta test dedicado:**
- `InvestmentItemCalculatorService`: cascada márgenes, current* init.

### 5. Event Listeners para side effects automáticos ✅ **Decisión: explícito en servicios**
Candidatos revisados:
- `UsdRate` postPersist -> actualiza setting: **resuelto** en `UsdRateService::setRate()` (líneas 91-103).
- `Investment` postPersist (confirmada) -> crea `InvestmentSummary`: **resuelto** en `InvestmentConfirmationService::confirm()`.
- `Sale` postPersist -> recalcula summary: **resuelto** en `SaleService::register()` y `SaleVoidService::void()`.
- `Payment` postPersist -> actualiza saldo cliente: **no necesario** (pagos no mueven recovery).

**Política:** side effects explícitos en el servicio (controlador llama). Migrar a listeners solo si crece complejidad.

### 6. Validación de `PaymentCommand::currency` ⏳ **Pendiente (bajo riesgo)**
**Actual:** `PaymentCommand` no tiene `currency`; se toma de `investment.baseCurrency`.
**Futuro:** Si se permite pago en moneda distinta, añadir `currency` al comando y validar.

### 7. `InvestmentStateMachine::updateInvestmentState` en servicios ✅ **Resuelto en Fase 3d**
`SaleService::updateInvestmentState()` y `SaleVoidService::updateInvestmentState()` lo hacen internamente. El controlador ya no necesita invocarlo.

### 8. `SaleService::register` - carga de ítems ⏳ **Pendiente (N+1)**
**Actual:** Busca ítems uno a uno (`findByIdAndTenant`) -> N+1 potencial.
**Mejora:** `InvestmentItemRepositoryInterface::findByIdsAndTenant(array $ids, int $tenantId)` ya existe (commit Fase 3c). Falta usarla en `SaleService::preloadRemainingStock` / `createSaleLine`.

### 9. `UsdRateService::setRate` - actualización de setting ✅ **Resuelto en Fase 3d**
El servicio actualiza `AppSetting::KEY_USD_RATE_CURRENT` inline (líneas 91-106) + invalida cache de `SettingsService`. No usa listener.

### 10. Soft deletes vs hard deletes ⏳ **Pendiente (consistencia)**
**Actual:** Entidades usan `cancelledAt` / `voidedAt` / `closedAt` (soft delete lógico).
**Pendiente:** Filtrar consistentemente en repositorios (algunos usan `status != CANCELLED`, otros no, `includeVoided` / `includeCancelled` opcionales).

### 11. Índices BD para consultas frecuentes ✅ **Revisados y cubiertos**
- `SaleLineRepository::findByInvestment` con `includeVoided`: índice `idx_saleline_tenant_item` + `idx_saleline_tenant_created`.
- `InventoryMovementRepository::getStockByInvestment`: índice `idx_movement_tenant_item`.
- `InvestmentSummary` dashboard: índice `idx_summary_tenant_pending`.

### 12. Documentación OpenAPI / Swagger ⏳ **Pendiente**
Pendiente hasta que existan controladores API. UI actual usa Turbo/Stimulus con renderizado server-side.

## Priorización sugerida
1. **Optimización N+1 en SaleService** (usar `findByIdsAndTenant` ya existente).
2. **Consistencia soft deletes** en repositorios.
3. **Test `InvestmentItemCalculatorService`** (cascada márgenes, current* init).
4. **OpenAPI** cuando haya API expuesta.

## Estado
Actualizado en cierre post-Fase 7. Ítems 1, 2, 3, 5, 7, 9, 11 resueltos;
4 mayormente resuelto (falta `InvestmentItemCalculatorService`);
6, 8, 10, 12 pendientes.

## Referencias
- `services.yaml` (autowiring + `#[AsAlias]` en repositorios).
- `migrations/` (esquema actual).
- `docs/adr/ADR-0017-flush-y-recompute-de-investment-summary.md` (política de flush).