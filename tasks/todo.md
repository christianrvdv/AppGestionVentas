# Task List: Fase 4.1 — Reportes Básicos

## Phase 1: Foundation — Services & DTOs
- [ ] Task 1: Crear DTOs de filtros (ProfitReportFilters, InvestmentReportFilters, ProductRankingFilters)
- [ ] Task 2: Crear ProfitReportService con lógica de ganancias por período
- [ ] Task 3: Crear InvestmentReportService con lógica de lista de inversiones
- [ ] Task 4: Crear ProductRankingService con lógica de ranking

## Checkpoint: Foundation Services
- [ ] `composer ci` verde (lint + tests existentes)

## Phase 2: Controllers & Routes
- [ ] Task 5: Crear ReportController con 3 acciones (profit, investment, product)

## Phase 3: Templates
- [ ] Task 6: Crear template base report/base.html.twig
- [ ] Task 7: Crear template report/profit.html.twig
- [ ] Task 8: Crear template report/investment.html.twig
- [ ] Task 9: Crear template report/product.html.twig

## Checkpoint: Templates Render
- [ ] Navegación manual: las 3 páginas cargan sin errores 500

## Phase 4: Navigation
- [ ] Task 10: Añadir "Reportes" al nav en base.html.twig

## Phase 5: Tests
- [ ] Task 11: Test de integración ProfitReportTest
- [ ] Task 12: Test de integración InvestmentReportTest
- [ ] Task 13: Test de integración ProductRankingTest

## Checkpoint: Tests
- [ ] `php bin/phpunit` pasa completo
- [ ] `composer ci` verde

## Phase 6: Final Polish
- [ ] Task 14: Verificación final `composer ci` y commit único