# Spec: Fase 4.1 — Reportes Básicos

## Objective
Construir el primer bloque de reportes que el usuario usa todos los días para ver números:
1. **Ganancias por período** (día/semana/mes) — Ruta GET `/reportes/ganancias`
2. **Inversiones: recuperadas vs. activas** — Ruta GET `/reportes/inversiones`
3. **Ranking de productos más rentables** — Ruta GET `/reportes/productos`
4. **Navegación** — Añadir "Reportes" al nav principal con submenú/pestañas

## Tech Stack
- Symfony 8.1.8, PHP 8.4, PostgreSQL 18
- Twig para server-rendered views
- KnpPaginatorBundle para paginación
- Chart.js (opcional, si da el tiempo) via importmap
- DAMA para tests de integración
- PHPStan level 5, PHPUnit 13.4

## Commands
```
Test:       php bin/phpunit
Lint:       php bin/console lint:container && php bin/console lint:twig templates/ && php bin/console lint:yaml config/ && php bin/phpstan
CI:         composer ci
Dev server: symfony serve -d
```

## Project Structure
```
src/
  Controller/
    ReportController.php          # Nuevo controller para los 3 reportes
  Repository/
    Contract/
      ReportRepositoryInterface.php  # (opcional, para futuros reports)
  Service/
    Report/
      ProfitReportService.php     # Lógica de reporte de ganancias
      InvestmentReportService.php # Lógica de reporte de inversiones
      ProductRankingService.php   # Lógica de ranking de productos
templates/
  report/
    base.html.twig               # Layout base para reportes
    profit.html.twig             # Tabla + chart ganancias
    investment.html.twig         # Tabla + barra progreso inversiones
    product.html.twig            # Tabla ranking productos
tests/
  Integration/
    Report/
      ProfitReportTest.php
      InvestmentReportTest.php
      ProductRankingTest.php
```

## Code Style
```php
// Controller action — thin, delega a service
#[Route('/reportes/ganancias', name: 'app_report_profit', methods: ['GET'])]
public function profit(Request $request): Response
{
    $tenantId = $this->requireTenantId();
    $dto = $this->buildProfitFilters($request);
    $data = $this->profitReportService->generate($tenantId, $dto);

    return $this->render('report/profit.html.twig', $data);
}

// Service — readonly, constructor promotion
final class ProfitReportService
{
    public function __construct(
        private readonly SaleRepositoryInterface $saleRepository,
    ) {}

    public function generate(int $tenantId, ProfitReportFilters $filters): ProfitReportData
    {
        // ...
    }
}

// DTO con readonly + constructor promotion
readonly class ProfitReportFilters
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $granularity = 'day', // day|week|month
        public ?int $investmentId = null,
        public ?int $customerId = null,
    ) {}
}
```

## Testing Strategy
- **Integration tests** (KernelTestCase + DAMA): un smoke test por reporte que:
  1. Sembrar datos (tenant, user, investment, items, sales, payments)
  2. GET la ruta con filtros
  3. Verificar respuesta 200 + datos básicos en template
- **Functional tests** (WebTestCase): render + filtros + resultado básico
- **Coverage**: target ≥ 80% en services nuevos

## Boundaries
- **Always**: Run `composer ci` antes de commit; tests pasan; PHPStan level 5
- **Ask first**: Cambios a schema BD, nuevas dependencias composer, cambios CI
- **Never**: Commit secrets, editar vendor/, quitar tests fallando sin aprobación

## Success Criteria
1. **Ganancias por período** (`/reportes/ganancias`):
   - Filtros: from, to, investment (opcional), customer (opcional), granularity (day|week|month)
   - Tabla: período, ventas, ganancia bruta, ganancia reconocida
   - Total del rango al pie
   - Usa `SaleRepository::sumSalesByPeriod`, `sumProfitByPeriod`, `sumRecognizedProfitByPeriod`

2. **Inversiones** (`/reportes/inversiones`):
   - Lista todas las inversiones con: código, fecha, estado, total invertido, total recuperado, % recuperación, ganancia reconocida, pendiente
   - Filtros: estado, rango de fechas
   - Ordenable por % recuperación o pendiente
   - Barra de progreso visual de recuperación
   - Usa `InvestmentSummaryRepository` (ya tiene todo)

3. **Ranking productos** (`/reportes/productos`):
   - Filtros: rango de fechas, límite (default 10)
   - Tabla: producto, unidades vendidas, ingreso total, ganancia bruta, margen
   - Usa `ProductRepository::findMostProfitable`

4. **Navegación**: "Reportes" en nav con submenú/pestañas: Ganancias | Inversiones | Productos

5. **Tests**: Un smoke test por reporte (render + filtros + resultado básico con datos sembrados)

6. **CI**: `composer ci` verde

7. **Commit único**: `feat(web): reportes básicos (ganancias, inversiones, productos) (Fase 4.1)`

## Open Questions
1. **Chart.js**: ¿Incluir chart simple (línea/barras) o solo tabla? → Decidí **solo tabla** en esta fase (más rápido, cumple DoD); chart en fase posterior si se pide.
2. **Granularity weekly/monthly**: ¿Agrupar en SQL o en PHP? → **En PHP** (reutiliza `dailyTotals` y agrupa), evita SQL complejo.
3. **Export CSV/PDF**: Fuera de alcance (fase posterior).

---

**Approved by human:** _______________ **Date:** _______________