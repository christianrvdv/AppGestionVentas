# Implementation Plan: Fase 5.1 — Gestión de tasas USD en UI

## Module Dependency Graph
```
usd-rate-crud (independent)
    ↓
investment-revaluation (needs current rate from usd-rate-crud)
    ↓
usd-exposure-indicator (reads InvestmentSummary updated by revaluation)
    ↓
revaluation-report (reads ItemCostRevaluation created by revaluation)
```

## Phase 1: usd-rate-crud (CRUD tasas USD)

### Tasks
- [ ] **Task 1.1**: Create `UsdRateFormType` form
  - Acceptance: Form with date (default today), rate (decimal > 0), note (optional)
  - Verify: `php bin/phpunit --filter=UsdRateFormType`
  - Files: `src/Form/UsdRateFormType.php`

- [ ] **Task 1.2**: Create `UsdRateController` with index action
  - Acceptance: GET `/tasas-usd` renders table with all columns, ordering, current rate badge, date filter
  - Verify: `php bin/phpunit --filter=UsdRateControllerTest::test_index`
  - Files: `src/Controller/UsdRateController.php`, `templates/usd_rate/index.html.twig`

- [ ] **Task 1.3**: Create `UsdRateController` new/create actions
  - Acceptance: GET/POST `/tasas-usd/nueva` creates rate; same date → correction + flash
  - Verify: `php bin/phpunit --filter=UsdRateControllerTest::test_new_and_create`
  - Files: `src/Controller/UsdRateController.php`, `templates/usd_rate/new.html.twig`

- [ ] **Task 1.4**: Add route to navigation (Reportes dropdown or main nav)
  - Acceptance: Link accessible from main navigation
  - Verify: Manual check in browser
  - Files: `templates/base.html.twig`

## Phase 2: investment-revaluation (Revaluar desde UI)

### Tasks
- [ ] **Task 2.1**: Add revalue action to `InvestmentController`
  - Acceptance: POST `/inversiones/{id}/revaluar` calls service, shows flash with impact
  - Verify: `php bin/phpunit --filter=InvestmentRevaluationControllerTest::test_revalue_open`
  - Files: `src/Controller/InvestmentController.php`

- [ ] **Task 2.2**: Add revaluation section to `investment/show.html.twig`
  - Acceptance: Section visible only for OPEN/PARTIAL; shows snapshot, current, last reval, history, button
  - Verify: `php bin/phpunit --filter=InvestmentRevaluationControllerTest::test_revaluation_section_visibility`
  - Files: `templates/investment/show.html.twig`

- [ ] **Task 2.3**: Handle domain errors (CLOSED, no current rate)
  - Acceptance: CLOSED → flash error; no current rate → clear error flash
  - Verify: `php bin/phpunit --filter=InvestmentRevaluationControllerTest::test_revalue_closed` + `test_revalue_no_rate`
  - Files: `src/Controller/InvestmentController.php`

## Phase 3: usd-exposure-indicator (Indicador exposición USD)

### Tasks
- [ ] **Task 3.1**: Add exposure indicator to investment detail
  - Acceptance: Shows "Valor actualizado: $X (histórico: $Y)" with colored delta
  - Verify: `php bin/phpunit --filter=InvestmentRevaluationControllerTest::test_exposure_indicator`
  - Files: `templates/investment/show.html.twig`

## Phase 4: revaluation-report (Reporte impacto revaluación)

### Tasks
- [ ] **Task 4.1**: Create `RevaluationReportFilters` DTO
  - Acceptance: DTO with from/to dates, validation
  - Verify: `php bin/phpunit --filter=RevaluationReportFiltersTest`
  - Files: `src/DTO/Report/RevaluationReportFilters.php`

- [ ] **Task 4.2**: Add revaluation action to `ReportController`
  - Acceptance: GET `/reportes/revaluacion` with date filter, table with all columns, totals
  - Verify: `php bin/phpunit --filter=RevaluationReportControllerTest::test_report`
  - Files: `src/Controller/ReportController.php`, `templates/report/revaluation.html.twig`

## Phase 5: Tests & Quality Gates

### Tasks
- [ ] **Task 5.1**: Write integration tests for all 7 scenarios
  - Acceptance: All 7 test methods pass
  - Verify: `php bin/phpunit --filter="UsdRate|InvestmentRevaluation|RevaluationReport"`
  - Files: `tests/Integration/UsdRate/UsdRateControllerTest.php`, `tests/Integration/Investment/InvestmentRevaluationControllerTest.php`, `tests/Integration/Report/RevaluationReportControllerTest.php`

- [ ] **Task 5.2**: Run full test suite with random order
  - Acceptance: `php bin/phpunit --order-by=random` exits 0
  - Verify: Command output

- [ ] **Task 5.3**: Run composer ci
  - Acceptance: `composer ci` exits 0 (PHPStan, CS, tests)
  - Verify: Command output

- [ ] **Task 5.4**: Create single commit with message
  - Acceptance: `git commit -m "feat(web): gestión de tasas USD y revaluación en UI (Fase 5.1)"`
  - Verify: `git log --oneline -1`

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Tenant isolation bugs | Every repository query includes tenantId; tests verify isolation |
| Flash message not showing | Use `addFlash()` after redirect; test asserts flash bag |
| Correction detection | Service returns entity; controller checks `isCorrection()` |
| Date handling | Always normalize to `DateTimeImmutable` date-only in service |
| CSRF on POST | Use `FormType` with CSRF or explicit `assertValidCsrf()` |

---

## Verification Checkpoints

1. **After Phase 1**: `php bin/phpunit tests/Integration/UsdRate/UsdRateControllerTest.php` — all green
2. **After Phase 2**: `php bin/phpunit tests/Integration/Investment/InvestmentRevaluationControllerTest.php` — all green
3. **After Phase 3**: Manual verification of exposure indicator on investment show
4. **After Phase 4**: `php bin/phpunit tests/Integration/Report/RevaluationReportControllerTest.php` — all green
5. **Final**: `php bin/phpunit --order-by=random && composer ci` — all green