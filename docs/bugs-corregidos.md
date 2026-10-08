# Fixes de dominio — 2026-10-08

## Estado

7 fixes + 1 bloqueante de soporte. **105 tests / 203 assertions** (baseline 79/123).
`doctrine:schema:validate` OK (mapping + BD), `lint:container` OK, `lint:twig` OK,
migración desde BD Postgres vacía OK (3 migraciones / 132 SQL).

Cada fix tiene al menos un test que **falla antes** del cambio y **pasa después**
(verificado revertiendo los archivos con `git checkout --` y re-ejecutando).

---

## Fixes aplicados

### Fix 1 — `Sale.totalAmount` persistido en `0.00`

- **Causa raíz:** `SaleLine.totalLine` solo se calculaba en `SaleLine::onPrePersist`
  (`recalculateDerived()`) y `Sale.totalAmount` en `Sale::onPrePersist`. Doctrine
  dispara `PrePersist` en orden de dependencia de claves foráneas: primero `Sale`
  (suma líneas con `totalLine = '0.00'`) y después `SaleLine` → el `INSERT` de la
  venta guarda `0.00`. Además, mutar `quantity`/`unitPrice` de una línea ya
  persistida no avisaba a la venta (`Sale::onPreUpdate` no dispara porque la venta
  no cambió) → `totalAmount` desfasado de `Σ líneas`.
- **Solución:** `recalculateTotalLine()` se ejecuta en el momento de
  `setQuantity()`/`setUnitPrice()` (defensivo con `isset`, orden de setters
  indistinto); `notifySaleTotal()` propaga a la venta →
  `Sale::recalculateTotalAmount()` (ahora público); `addLine()`/`removeLine()`
  también recalculan. `recalculateDerived()` queda como defensa en profundidad.
- **Test:** `tests/Integration/Sale/SaleTotalAmountTest` (4 tests).
- **Archivos:** `src/Entity/Sale.php`, `src/Entity/SaleLine.php`.
- **Matiz:** los tests de servicio pasaban sin el fix porque
  `applyRecognizedProfit() → recalculateDerived()` calcula `totalLine` antes del
  persist (verificado con instrumentación de callbacks). El bug real se reproduce
  en `test_setters_*` y `test_callbacks_*`. Cubierto.
- **Pre-fix (verificado):** `Tests: 4, Failures: 2` (los dos tests de entidades).

### Fix 2 — `UsdRateService` llamaba a un repositorio inexistente

- **Causa raíz:** `setRate()` invocaba `settingRepository->findByKeyAndTenant(clave, tenant)`,
  método que no existe ni en `AppSettingRepositoryInterface` ni en su
  implementación → `Error` en runtime en **toda** corrección de tasa USD.
- **Solución:** `findSetting(int $tenantId, string $key)` con el orden real de
  argumentos de la firma existente.
- **Test:** `tests/Integration/UsdRate/UsdRateServiceTest` (3 tests).
- **Archivos:** `src/Service/UsdRateService.php`.
- **Matiz:** el test de "corrección de una fecha anterior" se reescribió como
  "otra fecha" por OBS-2 (SQLite no coincide `datetime_immutable` con columnas
  `DATE`; en Postgres sí).
- **Pre-fix (verificado):** `Tests: 3, Errors: 2`.

### Fix 3 — Prorrateo de gastos colisiona con ítems sin persistir

- **Causa raíz:** los mapas de subtotales se indexaban por `$item->getId()`. Los
  ítems de una inversión en borrador aún no tienen ID (`getId()` no inicializado →
  `null`) y PHP convierte `null` en la clave `''`, así que **todos compartían
  clave** y el segundo pisa al primero. `allocateExpense()` además iteraba
  `$items->toArray()` sin renumerar, con lo que el "último ítem" (que absorbe el
  remanente) no siempre era el último.
- **Solución:** claves por `spl_object_id($item)` (única por instancia en memoria)
  en `allocateForInvestment()`, `allocateExpense()` y
  `recalculateItemAllocatedExpenses()`; `array_values(...)` para la numeración del
  último ítem. Funciona igual con ítems nuevos que persistidos (identity map).
- **Test:** `tests/Integration/ExpenseAllocation/ExpenseAllocationServiceTest` (3 tests).
- **Archivos:** `src/Service/ExpenseAllocationService.php`.
- **Pre-fix (verificado):** `Tests: 3, Errors: 3`.

### Fix 4 — Validación de allocations contra BD con flush pendiente

- **Causa raíz:** `validateExpenseAllocations()` consultaba
  `ExpenseAllocationRepository::sumByExpense()` (BD) mientras
  `ExpenseAllocationService` acababa de reconstruir los allocations **en memoria**
  (`clear()` + `addAllocation()` + `orphanRemoval`) sin flush intermedio →
  comparaba datos viejos/vacíos → `AllocationMismatchException` falsas, o aceptaba
  un prorrateo desfasado. De paso, el constructor inyectaba ese repositorio solo
  para esto y el import de `InvestmentItemCalculatorService` estaba mal
  (`App\Service\...` sin subcarpeta), lo que rompía la compilación del contenedor.
- **Solución:** suma **en memoria** sobre `$expense->getAllocations()` con `bcadd`
  y tolerancia ±`ALLOCATION_TOLERANCE` (`0.01`); se elimina el repositorio del
  constructor; **fix extra (bloqueante)**: import corregido a
  `App\Service\Investment\InvestmentItemCalculatorService` en
  `InvestmentExpenseService` — sin él no arrancaba el contenedor de test.
- **Test:** `tests/Integration/Investment/InvestmentConfirmationServiceTest` (3 tests).
- **Archivos:** `src/Service/Investment/InvestmentConfirmationService.php`,
  `src/Service/Investment/InvestmentExpenseService.php` (import).
- **Pre-fix (verificado):** `Tests: 3, Errors: 3`.

### Fix 5 — `TenantFilter` interpola SQL crudo y no cubre tenant `0`

- **Causa raíz:** `SQLFilter::getParameter()` devuelve el valor **ya
  entrecomillado** (`Connection::quote()`, p. ej. `'5'`). Con `%s` se inyectaba el
  literal SQL tal cual, y la rama "sin tenant" comparaba
  `$tenantId === '0'`, que **no** cubre `"'0'"` (entrecomillado) ni el int `0`.
- **Solución:** `trim($tenantId, "'")` + cast a `int`; `$tenantIdInt <= 0` →
  `'1 = 0'`; `sprintf('... = %d', ..., $tenantIdInt)`. El cast + `%d` es también
  la defensa contra inyección (un valor no numérico se trunca a su prefijo entero
  y jamás se interpola como SQL crudo).
- **Test:** `tests/Integration/Doctrine/TenantFilterTest` (6 tests: filtrado por
  tenant, cambio de tenant, **SQL generado** (`tenant_id = 5` vs
  `tenant_id = '5'`), payload `1 OR 1 = 1`, sin parámetro, tenant `0`).
- **Archivos:** `src/Doctrine/Filter/TenantFilter.php`.
- **Pre-fix (verificado):** `Tests: 6, Failures: 2`.

### Fix 6 — `TenantContext` sin reset entre requests

- **Causa raíz:** en workers persistentes (RoadRunner / FrankenPHP / Swoole) los
  servicios son singletons por worker y sobreviven entre requests. Si
  `TenantFilterListener` no llega a correr (error previo, sub-request, bypass), el
  contexto conserva el `tenantId` de la petición anterior y **contamina la
  siguiente**.
- **Solución:** `TenantContext implements ResetInterface` con
  `reset(): void { $this->clear(); }`; el autoconfigure lo etiqueta
  `kernel.reset` y `ServicesResetter` lo invoca al terminar cada request.
- **Test:** `tests/Integration/Service/TenantContextResetTest` (3 tests:
  `instanceof ResetInterface`, `reset()` limpia, `services_resetter` lo resetea).
- **Archivos:** `src/Service/TenantContext.php`.
- **Verificación extra:** `bin/console debug:container --tag=kernel.reset` lista
  `App\Service\TenantContext  reset`.
- **Pre-fix (verificado):** `Tests: 3, Errors: 1, Failures: 2`.

### Fix 7 — `Payment.customer` obligatorio (Estrategia B)

- **Causa raíz:** el mapping era `nullable: false` con tipo `Customer` (no null)
  mientras `PaymentCommand.customerId` **sí** es nullable. `PaymentService`
  pasaba `null` a `findByIdAndTenant(int, int)` → `TypeError` en runtime, y la
  validación `customer == sale.customer` se caía con ventas de mostrador. Un pago
  de mostrador era imposible de persistir.
- **Solución (Estrategia B):** `?Customer $customer = null`,
  `JoinColumn(nullable: true, onDelete: 'RESTRICT')`, `getCustomer(): ?Customer`;
  en el servicio la búsqueda de cliente solo ocurre si `customerId !== null`, y la
  comparación de cliente solo si **ambos** (pago y venta) están definidos.
  Estrategia A (cliente obligatorio) descartada: habría roto el flujo de mostrador.
- **Migración:** `migrations/Version20261008153127.php` →
  `ALTER TABLE payment ALTER customer_id DROP NOT NULL` (down: `SET NOT NULL`).
- **Test:** `tests/Integration/Payment/PaymentServiceTest` (4 tests: pago sin
  cliente, con cliente, venta de mostrador, mismatch de cliente).
- **Archivos:** `src/Entity/Payment.php`, `src/Service/Payment/PaymentService.php`,
  `src/DTO/Command/PaymentCommand.php`, `migrations/Version20261008153127.php`.
- **Pre-fix (verificado):** `Tests: 4, Errors: 2`.

### Soporte — `config/services.yaml` (`when:test:`)

Los servicios de dominio no tienen controllers, así que no entran al contenedor
compilado en test. Se añadió un bloque `when:test:` con `_defaults`
(**no se hereda entre bloques**, hay que repetirlo) que expone `App\Service\*`
como `public` con `exclude: '../src/Service/Money/'` (`Money` tiene constructor
privado). Hacer público todo `src/` rompe por DTO/Exception con argumentos
escalares. Permite `static::getContainer()->get(SaleService::class)` en los tests
de integración.

---

## Observaciones fuera de alcance

### OBS-1 — Alias DQL `is` en `InvestmentSummaryRepository`

**Bloqueante real. Prioridad: alta.**

`src/Repository/InvestmentSummaryRepository.php` usa el alias `is`
(palabra reservada del parser DQL) en `findByInvestment()`, `findTopRecovered()`
y en la consulta agregada → `[Syntax Error] ... Expected IdentificationVariable ..., got 'is'`.

Impacto: `InvestmentSummaryService::recompute()` falla **siempre**, en cualquier
plataforma, y `SaleService::register()` (paso 13) lanza `QueryException` tras
haber persistido el sale y sus líneas.

Mitigación actual en tests: `SaleTotalAmountTest::registerSaleToleratingSummaryDqlBug()`
tolera ese `QueryException` (documentado en el propio docblock). **No corregido
por estar fuera del alcance de [FIX-1].**

### OBS-2 — Comparación `DATE` vs `datetime_immutable` en SQLite

**Prioridad: media.**

`src/Repository/UsdRateRepository.php` (`findEffectiveByDate()`,
`findCurrentBefore()`, `findByDateRange()`) compara parámetros inferidos como
`datetime_immutable` (`'Y-m-d H:i:s'`) contra columnas `DATE` (`'Y-m-d'`). En
PostgreSQL el motor convierte y funciona; en SQLite nunca coincide. Afecta solo a
los tests (`app_env=test` fuerza SQLite en memoria).

### OBS-3 — Hallazgos varios

- **DAMA sin extensión PHPUnit activa** en `phpunit.dist.xml`
  (`enable_static_connection` sí está en `when@test`): no hay rollback de
  transacciones entre tests; se compensa con `IntegrationTestCase::recreateSchema()`
  (SchemaTool por test) y con `IntegrationTestCase::assertDecimalEquals()` — las
  columnas DECIMAL de SQLite devuelven `'25'` en vez de `'25.00'`.
- **`InvestmentItemCalculatorService` inyectado sin uso** en
  `InvestmentConfirmationService`.
- **`AllocationMismatchException::forExpense(int)` lanza `Error`** con expense
  nuevo (el id no está inicializado en el mensaje).
- **`CurrencyMismatchException` importado sin usar** en `PaymentService`.

---

## Cómo verificar

```bash
# Suite completa
bin/phpunit                     # → 105 tests, 203 assertions, OK
vendor/bin/phpunit              # equivalente

# Mapping vs BD (dev Postgres, con migración aplicada)
bin/console doctrine:schema:validate          # → Mapping OK, Database OK

# Contenido
bin/console lint:container                    # → OK
bin/console lint:twig templates/              # → OK

# Migración desde cero en una BD vacía
$env:DATABASE_URL="postgresql://.../<bd_nueva>?serverVersion=18"
bin/console doctrine:database:create --if-not-exists
bin/console doctrine:migrations:migrate -n    # → 3 migraciones, 132 SQL, OK
bin/console doctrine:schema:validate          # → OK
bin/console doctrine:database:drop --force
```

**Fail-before (revertir el fix y correr su test):**

```bash
git checkout -- src/Entity/Sale.php src/Entity/SaleLine.php   # Fix 1 → Failures: 2
git checkout -- src/Service/UsdRateService.php                # Fix 2 → Errors: 2
git checkout -- src/Service/ExpenseAllocationService.php      # Fix 3 → Errors: 3
git checkout -- src/Service/Investment/InvestmentConfirmationService.php  # Fix 4 → Errors: 3
git checkout -- src/Doctrine/Filter/TenantFilter.php          # Fix 5 → Failures: 2
git checkout -- src/Service/TenantContext.php                 # Fix 6 → Errors: 1, Failures: 2
git checkout -- src/Entity/Payment.php src/Service/Payment/PaymentService.php src/DTO/Command/PaymentCommand.php   # Fix 7 → Errors: 2
```

(Versión automatizada: `Temp\opencode\verify-fixes.ps1` — revierte cada fix,
corre su test filtrado, restaura y termina con la suite completa.)
