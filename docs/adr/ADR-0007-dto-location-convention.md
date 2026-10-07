# ADR-0007: Ubicación de DTOs y Convención de Comandos

## Contexto
Se necesitan DTOs inmutables para comandos de entrada a servicios (RegisterSaleCommand, PaymentCommand, etc.).

## Decisión
**Ubicación**: `App\DTO\Command\*` (namespace plano, no por servicio).

**Razones:**
1. **Visibilidad**: Los DTOs de comando son parte de la API pública de la capa de servicios. Agruparlos en `DTO\Command` los hace fáciles de encontrar e importar.
2. **Compartidos**: Un DTO puede usarse en múltiples servicios (ej. `PaymentCommand` usado por `PaymentService` y potencialmente por `SaleService` para pagos en venta).
3. **Convención clara**: `Command` = entrada (write), futuro `Query` = salida (read). Separación CQRS ligera.
4. **Evita ciclos**: Si `SaleService` usa `RegisterSaleCommand` y `RegisterSaleCommand` estuviera en `App\Service\Sale\Command`, tendríamos que importar desde el servicio. En `DTO\Command` es neutral.

**Estructura:**
```
App\DTO\
├── Command\
│   ├── RegisterSaleCommand.php
│   ├── RegisterSaleLineCommand.php
│   └── PaymentCommand.php
└── (futuro: Query\ para DTOs de lectura)
```

**Reglas de DTOs de comando:**
- `readonly` class + `readonly` properties (PHP 8.2+)
- Validación básica en constructor (tipos, rangos simples)
- Validación de negocio **NO** aquí; va en el servicio
- Sin lógica, solo datos
- Nombres: `*Command` para escrituras, `*Query` para lecturas

## Alternativas descartadas
- `App\Service\Sale\Command\RegisterSaleCommand`: Acopla DTO a servicio, dificulta reuso.
- `App\Service\Command\RegisterSaleCommand`: Demasiado genérico, mezcla contextos.

## Estado
Aceptado. Implementado en `App\DTO\Command\`.

## Referencias
- `App\Service\Sale\SaleService::register(RegisterSaleCommand $cmd, ...)`
- `App\Service\Payment\PaymentService::register(PaymentCommand $cmd, ...)`