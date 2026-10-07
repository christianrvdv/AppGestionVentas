# ADR-0008: Deuda Técnica Pendiente

## Contexto
Durante la implementación de la capa de servicios se identificaron decisiones que requieren seguimiento futuro (migraciones, tests, ajustes de esquema, wiring de dependencias).

## Ítems pendientes

### 1. Inyección de repositorios faltantes en servicios
**Servicios afectados:**
- `SaleService::loadInvestment()` requiere `InvestmentRepositoryInterface`
- `SaleVoidService::updateInvestmentState()` requiere `InvestmentSummaryRepositoryInterface`
- `InvestmentConfirmationService` usa `ExpenseAllocationRepositoryInterface` (inyectado OK)

**Acción:** Inyectar repositorios faltantes en constructores y actualizar `services.yaml`.

### 2. Método `InvestmentRepositoryInterface::countByCodePrefix`
**Estado:** Añadido a interfaz e implementación (ver commit).
**Pendiente:** Verificar que no rompe tests existentes.

### 3. Relación `Investment <-> InvestmentSummary`
**Actual:** Unidireccional (`InvestmentSummary -> Investment`).
**Necesario:** Bidireccional para navegación fácil (`$investment->getSummary()`).
**Migración:** Añadir `#[ORM\OneToOne(mappedBy: 'investment', cascade: ['persist'])]` en `Investment::$summary`.

### 4. Tests unitarios e integración
**Alcance:**
- `Money`: operaciones bcmath, redondeo, comparaciones.
- `ExpenseAllocationService`: prorrateo, remanente último ítem, idempotencia.
- `InvestmentItemCalculatorService`: cascada márgenes, current* init.
- `InvestmentConfirmationService`: validaciones, movimientos, totales.
- `SaleService`: stock, profit recognition ambos modos, idempotencia.
- `SaleVoidService`: bloqueo por pagos, reversión profit.
- `LossService`: stock, revaluación summary.
- `InvestmentRevaluationService`: gain/loss signo, current* update.
- `PaymentService`: validación cliente/venta.

**Framework:** `symfony/test-pack` + `phpunit`. Extender `KernelTestCase` para servicios con BD.

### 5. Event Listeners para side effects automáticos
**Candidatos:**
- `UsdRate` postPersist -> actualiza `AppSetting::KEY_USD_RATE_CURRENT`
- `Investment` postPersist (confirmada) -> crea `InvestmentSummary` inicial
- `Sale` postPersist -> recalcula summary, actualiza estado
- `Payment` postPersist -> actualiza saldo cliente

**Decisión:** Por ahora side effects explícitos en servicios (controlador llama). Migrar a listeners si crece complejidad.

### 6. Validación de `PaymentCommand::currency`
**Actual:** `PaymentCommand` no tiene `currency`; se toma de `investment.baseCurrency`.
**Futuro:** Si se permite pago en moneda distinta, añadir `currency` al comando y validar.

### 7. `InvestmentStateMachine::updateInvestmentState` en servicios
**Actual:** `SaleService` y `SaleVoidService` delegan al controlador.
**Mejora:** Inyectar `InvestmentSummaryRepositoryInterface` y hacerlo automático en el servicio.

### 8. `SaleService::register` - carga de ítems
**Actual:** Busca ítems uno a uno (`findByIdAndTenant`) -> N+1 potencial.
**Mejora:** Añadir `InvestmentItemRepositoryInterface::findByIds(array $ids, int $tenantId)` y cargar en batch.

### 9. `UsdRateService::setRate` - actualización de setting
**Actual:** Comentario dice "controlador actualiza setting post-flush".
**Mejora:** EventListener `postPersist` en `UsdRate` que actualice `AppSetting`.

### 10. Soft deletes vs hard deletes
**Actual:** Entidades usan `cancelledAt` / `voidedAt` / `closedAt` (soft delete lógico).
**Pendiente:** Filtrar consistentemente en repositorios (algunos usan `status != CANCELLED`, otros no).

### 11. Índices BD para consultas frecuentes
**Revisar:** `SaleLineRepository::findByInvestment` con `includeVoided` - indice optimo?
**Revisar:** `InventoryMovementRepository::getStockByInvestment` - query eficiente?

### 12. Documentación OpenAPI / Swagger
**Pendiente:** Generar spec para endpoints de la API cuando se creen controladores.

## Priorización sugerida
1. **Tests** (critico para refactoring seguro)
2. **Wiring repositorios faltantes** (blockers para tests)
3. **Relación Investment-Summary bidireccional** (limpieza de API)
4. **Event Listeners** (reduccion acoplamiento controlador)
5. **Optimizaciones N+1 e indices** (rendimiento)

## Estado
Documentado. Seguimiento en backlog tecnico.

## Referencias
- `services.yaml` (wiring real)
- `migrations/` (proximas migraciones)