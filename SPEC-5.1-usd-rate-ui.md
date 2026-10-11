# Spec: Fase 5.1 — Gestión de tasas USD en UI

## Objective
Expose the existing USD rate domain functionality (UsdRateService, InvestmentRevaluationService, ItemCostRevaluation) through a minimal web UI so users can:
1. View and manage USD rates with chained corrections
2. Revalue investments from the investment detail page
3. View USD exposure indicator on investment detail
4. View revaluation impact report

**User**: Business operator managing multi-currency investments
**Success**: All 7 test scenarios pass, composer ci green, single commit

---

## Tech Stack
- Symfony 8.1.8, PHP 8.4, PostgreSQL 18
- Twig templates, Tailwind CSS (via importmap)
- Doctrine ORM, PHPStan level 6/5
- DAMA architecture (Domain, Application, Infrastructure, Interface)

---

## Commands
```bash
# Development
symfony serve -d

# Testing
php bin/phpunit --filter=UsdRate
php bin/phpunit --filter=InvestmentRevaluation
php bin/phpunit --order-by=random

# Code Quality
vendor/bin/phpstan analyse --level=5 src/
vendor/bin/php-cs-fixer fix --dry-run --diff
composer ci
```

---

## Project Structure (New/Modified Files)

```
src/
├── Controller/
│   ├── UsdRateController.php           # NEW - CRUD tasas USD
│   └── InvestmentController.php        # MODIFY - add revalue action
├── Form/
│   └── UsdRateFormType.php             # NEW - form for rate input
├── Service/
│   └── Investment/
│       └── InvestmentRevaluationService.php  # EXISTING - used by controller
├── Repository/
│   └── UsdRateRepository.php           # EXISTING - query methods
└── DTO/
    └── Report/
        └── RevaluationReportFilters.php # NEW - report filters

templates/
├── usd_rate/
│   ├── index.html.twig                 # NEW - rate list
│   └── new.html.twig                   # NEW - rate form
├── investment/
│   └── show.html.twig                  # MODIFY - add revaluation section + exposure
└── report/
    └── revaluation.html.twig           # NEW - revaluation impact report

tests/
├── Integration/
│   ├── UsdRate/
│   │   └── UsdRateControllerTest.php   # NEW - controller tests
│   ├── Investment/
│   │   └── InvestmentRevaluationControllerTest.php # NEW
│   └── Report/
│       └── RevaluationReportControllerTest.php     # NEW
```

---

## Code Style
- PHP attributes for routes, validation, DI
- Constructor property promotion, readonly DTOs
- Controllers thin, delegate to services
- Flash messages for domain errors (never 500)
- Tenant isolation via TenantContext in every query

```php
// Controller pattern
#[Route('/tasas-usd')]
final class UsdRateController extends AbstractController {
    public function __construct(
        private readonly UsdRateService $usdRateService,
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('', name: 'app_usd_rate_index', methods: ['GET'])]
    public function index(Request $request): Response { ... }
}
```

---

## Testing Strategy
- **Framework**: PHPUnit (extends `IntegrationTestCase`)
- **Location**: `tests/Integration/{module}/`
- **Coverage**: 7 scenarios from DoD + tenant isolation
- **Levels**: Integration tests for controllers + services
- **Isolation**: Each test creates own tenant/user, rolls back transaction

---

## Boundaries

| Category | Rules |
|----------|-------|
| **Always** | Run tests before commit, follow naming conventions, validate inputs, tenant isolation |
| **Ask First** | Database schema changes, adding dependencies, changing CI config |
| **Never** | Commit secrets, edit vendor, remove failing tests, hand-write SQL migrations |

---

## Success Criteria (from DoD)

1. **CRUD tasas USD funcional**
   - GET `/tasas-usd` → table with date, rate, correction badge, supersedes, author, timestamp, note
   - Order: rateDate DESC, createdAt DESC
   - Current rate marked with badge
   - Date range filter
   - GET/POST `/tasas-usd/nueva` → form with date (default today), rate (>0), note (optional)
   - On same date → creates chained correction, flash: "Tasa registrada como corrección de la existente"

2. **Revaluación desde UI funcional**
   - Section "Revaluación USD" on `/inversiones/{id}` only for OPEN/PARTIAL
   - Button "Revaluar con tasa actual" → POST `/inversiones/{id}/revaluar`
   - Calls `InvestmentRevaluationService::revalue()` with current rate
   - Flash: "Inversión revaluada. Impacto: ±$X"
   - Section shows: snapshot rate, current rate, last revaluation, collapsible history

3. **Indicador de exposición USD**
   - On investment detail: "Valor actualizado a tasa vigente: $X (histórico: $Y)"
   - Delta with sign and color (green = cost dropped, red = cost rose)
   - Uses `InvestmentSummary.totalInvestmentCurrent` vs `totalInvestment`

4. **Reporte de impacto de revaluación**
   - GET `/reportes/revaluacion`
   - Date range filter
   - Table: date, investment, item, old rate, new rate, old cost, new cost, delta, quantity snapshot
   - Totals: sum of deltas per period
   - Uses `ItemCostRevaluationRepository::findByDateRange` and `sumGainLossByInvestment`

5. **Tests cubren 7 escenarios**
   - New rate → created without correction
   - Same date rate → created as correction
   - Revalue OPEN → ItemCostRevaluation registered, currentRealUnitCost updated
   - Revalue CLOSED → domain error → flash
   - Revalue without current rate → clear error
   - Revaluation report returns correct data
   - Tenant isolation on all routes

6. **Quality gates**
   - `--order-by=random` passes
   - `composer ci` passes
   - Single commit: `feat(web): gestión de tasas USD y revaluación en UI (Fase 5.1)`

---

## Open Questions

1. **Correction notification timing**: The spec says "Informar en el flash". Should this be:
   - Before submit (JS check if date exists)?
   - After submit (server-side detection via service return)?
   → **Decision: After submit** — the service returns the created entity; controller checks `isCorrection()` and sets appropriate flash. Simpler, no JS needed.

2. **Date picker default**: "default hoy" — use `new \DateTimeImmutable('today')` in form defaults.

3. **Report totals**: "suma de deltas por período" — group by month? Or single total? → Single grand total for the filtered range.

4. **Revaluation history collapsible**: Use `<details>/<summary>` native HTML for zero-JS.

---

## Capability Map (Phase 0)

| Module id | Responsibility | Depends on |
|-----------|----------------|------------|
| usd-rate-crud | List/create USD rates | — |
| investment-revaluation | Revalue investment from detail | usd-rate-crud (needs current rate) |
| usd-exposure-indicator | Show current vs historical value on investment | investment-revaluation |
| revaluation-report | Report of revaluation impact | usd-rate-crud, investment-revaluation |

**Build order**: usd-rate-crud → investment-revaluation → usd-exposure-indicator → revaluation-report