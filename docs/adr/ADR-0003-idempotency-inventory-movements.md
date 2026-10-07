# ADR-0003: Idempotencia de Movimientos de Inventario y Ventas

## Contexto
Las operaciones de inventario (compras, ventas, mermas, devoluciones) deben ser idempotentes: reintentar la misma operación no debe crear duplicados.

## Decisión
**Clave idempotente determinista** por tipo de operación, almacenada en `InventoryMovement.idempotencyKey` con unique constraint compuesto `(tenant_id, idempotency_key)`.

| Operación | Formato clave | Ejemplo |
|-----------|---------------|---------|
| Compra (PURCHASE) | `purchase:item:{investmentItemId}` | `purchase:item:42` |
| Venta (SALE) | `sale:{saleId}:line:{lineId}` | `sale:100:line:5` |
| Merma (LOSS) | `loss:item:{itemId}:{uuid}` | `loss:item:42:a1b2c3d4` |
| Devolución (CUSTOMER_RETURN) | `return:sale:{saleId}:line:{lineId}` | `return:sale:100:line:5` |
| Manual | `manual:{random}` | `manual:a1b2c3d4e5f6...` |

**Implementación:**
- `InventoryMovement::generatePurchaseKey()`, `generateSaleKey()`, `generateLossKey()`, `generateReturnKey()`, `generateManualKey()`
- Unique constraint a nivel de BD: `uniq_movement_tenant_idempotency (tenant_id, idempotency_key)`
- Antes de crear, el servicio verifica `existsForReference()` o deja que la BD falle y captura la excepción.

**Por qué no UUID aleatorio para todo:**
- Claves deterministas permiten reintentos seguros desde el cliente (red, UI doble clic).
- UUID requeriría guardar mapping externo o consultar antes de insertar.
- Compuesto por tenant evita colisiones cross-tenant.

## Consecuencias
- Positiva: Reintentos seguros, auditoría trazable, cero duplicados en BD.
- Negativa: Requiere que saleId y lineId existan antes de generar clave de venta (pre-persist flush necesario para obtener IDs).

## Estado
Aceptado. Implementado en `App\Entity\InventoryMovement` y servicios `SaleService`, `SaleVoidService`, `LossService`, `InvestmentConfirmationService`.

## Referencias
- `App\Service\Sale\SaleService::processSaleLine()`
- `App\Service\Sale\SaleVoidService::createReturnMovement()`
- `App\Service\Inventory\LossService::register()`
- `App\Service\Investment\InvestmentConfirmationService::createPurchaseMovements()`