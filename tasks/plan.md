# Implementation Plan: Fase 4.1 — Reportes Básicos

## Overview
Construir 3 reportes básicos (Ganancias, Inversiones, Productos) con sus rutas, controladores, servicios, templates y tests. Añadir navegación "Reportes" al menú principal. Commit único al final.

## Architecture Decisions
- **Chart.js**: NO incluido en esta fase (solo tabla). Justificación: cumple DoD, más rápido, chart en fase posterior si se pide.
- **Granularity weekly/monthly**: Agrupación en PHP reutilizando `SaleRepository::dailyTotals()` + merge en service. Evita SQL complejo y reutiliza código existente.
- **Services**: Un service por reporte (ProfitReportService, InvestmentReportService, ProductRankingService) para mantener controllers thin.
- **DTOs**: Filtros tipados con `readonly class` + constructor promotion.
- **Nav**: Submenú dropdown en base.html.twig (pestañas dentro de cada reporte si se prefiere, pero dropdown es más estándar).

## Task List

### Phase 1: Foundation — Services & DTOs
- [ ] Task 1: Crear DTOs de filtros (ProfitReportFilters, InvestmentReportFilters, ProductRankingFilters)
  - Acceptance: 3 clases readonly en `src/DTO/Report/` con campos tipados
  - Verify: `php bin/phpstan` pasa, sintaxis correcta
  - Files: `src/DTO/Report/ProfitReportFilters.php`, `src/DTO/Report/InvestmentReportFilters.php`, `src/DTO/Report/ProductRankingFilters.php`
  - Scope: S (3 archivos)

- [ ] Task 2: Crear ProfitReportService con lógica de ganancias por período
  - Acceptance: Service usa SaleRepository::sumSalesByPeriod, sumProfitByPeriod, sumRecognizedProfitByPeriod; agrupa por día/semana/mes en PHP
  - Verify: `php bin/phpstan` pasa, tests unitarios si se añaden
  - Files: `src/Service/Report/ProfitReportService.php`
  - Scope: M (1 archivo + lógica)

- [ ] Task 3: Crear InvestmentReportService con lógica de lista de inversiones
  - Acceptance: Service usa InvestmentSummaryRepository + InvestmentRepository; calcula % recuperación, ordena, filtra
  - Verify: `php bin/phpstan` pasa
  - Files: `src/Service/Report/InvestmentReportService.php`
  - Scope: M (1 archivo)

- [ ] Task 4: Crear ProductRankingService con lógica de ranking
  - Acceptance: Service usa ProductRepository::findMostProfitable; devuelve datos para tabla
  - Verify: `php bin/phpstan` pasa
  - Files: `src/Service/Report/ProductRankingService.php`
  - Scope: S (1 archivo)

### Checkpoint: Foundation Services
- [ ] `composer ci` verde (lint + tests existentes)

### Phase 2: Controllers & Routes
- [ ] Task 5: Crear ReportController con 3 acciones (profit, investment, product)
  - Acceptance: 3 rutas GET /reportes/ganancias, /reportes/inversiones, /reportes/productos; cada una delega a su service y renderiza template
  - Verify: `php bin/console debug:router` muestra las 3 rutas; `php bin/phpstan` pasa
  - Files: `src/Controller/ReportController.php`
  - Scope: M (1 archivo)

### Phase 3: Templates
- [ ] Task 6: Crear template base report/base.html.twig (extiende base.html.twig)
  - Acceptance: Layout común con título, breadcrumbs, área de filtros
  - Verify: Render sin errores
  - Files: `templates/report/base.html.twig`
  - Scope: S (1 archivo)

- [ ] Task 7: Crear template report/profit.html.twig (tabla ganancias + totales)
  - Acceptance: Tabla con columnas: período, ventas, ganancia bruta, ganancia reconocida; fila total al pie; formulario filtros (from, to, granularity, investment, customer)
  - Verify: Render con datos de prueba
  - Files: `templates/report/profit.html.twig`
  - Scope: M (1 archivo)

- [ ] Task 8: Crear template report/investment.html.twig (tabla inversiones + barra progreso)
  - Acceptance: Tabla: código, fecha, estado, total invertido, total recuperado, % recuperación (barra), ganancia reconocida, pendiente; formulario filtros (estado, from, to); ordenable por % recuperación y pendiente
  - Verify: Render con datos de prueba
  - Files: `templates/report/investment.html.twig`
  - Scope: M (1 archivo)

- [ ] Task 9: Crear template report/product.html.twig (tabla ranking productos)
  - Acceptance: Tabla: producto, unidades vendidas, ingreso total, ganancia bruta, margen; formulario filtros (from, to, límite); top 10 por defecto
  - Verify: Render con datos de prueba
  - Files: `templates/report/product.html.twig`
  - Scope: M (1 archivo)

### Checkpoint: Templates Render
- [ ] Navegación manual: las 3 páginas cargan sin errores 500

### Phase 4: Navigation
- [ ] Task 10: Añadir "Reportes" al nav en base.html.twig (dropdown con 3 enlaces)
  - Acceptance: Dropdown funcional con enlaces a las 3 rutas; estado activo resaltado
  - Verify: Navegación manual funciona
  - Files: `templates/base.html.twig`
  - Scope: XS (1 archivo)

### Phase 5: Tests
- [ ] Task 11: Test de integración ProfitReportTest (smoke: render + filtros + datos sembrados)
  - Acceptance: Test crea tenant, investment, items, sales; GET /reportes/ganancias con filtros; verifica 200 + datos en respuesta
  - Verify: `php bin/phpunit tests/Integration/Report/ProfitReportTest.php` pasa
  - Files: `tests/Integration/Report/ProfitReportTest.php`
  - Scope: M (1 archivo)

- [ ] Task 12: Test de integración InvestmentReportTest (smoke: render + filtros + datos sembrados)
  - Acceptance: Test crea tenant, investments con summaries; GET /reportes/inversiones con filtros; verifica 200 + datos
  - Verify: `php bin/phpunit tests/Integration/Report/InvestmentReportTest.php` pasa
  - Files: `tests/Integration/Report/InvestmentReportTest.php`
  - Scope: M (1 archivo)

- [ ] Task 13: Test de integración ProductRankingTest (smoke: render + filtros + datos sembrados)
  - Acceptance: Test crea tenant, products, sales; GET /reportes/productos con filtros; verifica 200 + datos
  - Verify: `php bin/phpunit tests/Integration/Report/ProductRankingTest.php` pasa
  - Files: `tests/Integration/Report/ProductRankingTest.php`
  - Scope: M (1 archivo)

### Checkpoint: Tests
- [ ] `php bin/phpunit` pasa completo
- [ ] `composer ci` verde

### Phase 6: Final Polish
- [ ] Task 14: Verificación final `composer ci` y commit único
  - Acceptance: CI verde; commit con mensaje convencional
  - Verify: `git status` limpio, `composer ci` pasa
  - Files: —
  - Scope: XS

## Risks and Mitigations
| Risk | Impact | Mitigation |
|------|--------|------------|
| SaleRepository no tiene métodos semanales/mensuales | Medium | Agrupar en PHP desde dailyTotals (ya implementado) |
| InvestmentSummaryRepository no expone todos los campos necesarios | Low | Verificado: tiene totalInvestment, totalRecovered, recoveryPct, totalPending, totalRecognizedProfit |
| Tests lentos por DAMA | Low | Un test por reporte, datos mínimos |
| PHPStan errores en DTOs readonly | Low | Usar PHP 8.4 readonly class correctamente |

## Open Questions
- Resueltas en SPEC: solo tabla, agrupación PHP, sin Chart.js