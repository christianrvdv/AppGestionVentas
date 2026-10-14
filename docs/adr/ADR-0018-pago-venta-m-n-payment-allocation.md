# ADR-0018: Pago<->venta como M:N mediante PaymentAllocation

- **Estado:** Aceptado (Fase 3f).
- **Fecha:** 2026-10-14.
- **Contexto:** Fase 3f "Aplicar anticipos a ventas". El modelo anterior
  guardaba el vínculo en `payment.sale_id` (1:N), que solo puede expresar
  "este pago cubre exactamente esta venta, entera". Un anticipo del cliente
  no funciona así en la práctica: el cliente entrega una plata y pide que se
  le descuente de dos ventas, o deja 300 sin gastar para la que viene.

## Problema

Con `payment.sale_id` hay tres cosas que el modelo no puede decir:

| Caso | Por qué no se puede |
| --- | --- |
| Un anticipo cubre dos ventas | `sale_id` es una sola columna; la segunda venta quedaría sin cobro o el pago se duplicaría |
| Una venta recibe dos cobros | Dos filas de `payment` apuntando a la misma `sale_id` sí caben, pero ambas cuentan "pagado" por su **monto completo**, así que un cobro parcial contaría de más |
| Un cobro parcial | `Payment.amount` es el dinero que entró; no hay campo para "cuánto de eso se aplicó a esta venta" |

El tercer punto es el que corrige este ADR en la dirección contraria a la
intuitiva: **`payment.amount` no se toca, sigue siendo el dinero que
entró**. Lo que cambia es que "aplicado" pasa a ser una suma de
`payment_allocation.amount`. Deja de haber una sola cifra "pagado" por
pago y empieza a haber dos: cobrado (la entrada) y aplicado (el reparto).

### Consecuencia indirecta en los reportes

`PaymentRepository::sumByCustomer()` **no** cambia de significado, y eso es
deliberado. Sigue midiendo dinero **entrado** (`SUM(p.amount)` por
cliente), que es el saldo del cliente: cuánto nos debe / nos ha entregado.
Si pasara a sumar allocations mediría dinero **aplicado**, que es otra
pregunta, y el saldo del cliente dejaría de ser el saldo.

Lo que sí suma allocations es `sumBySale()` (pagado de una venta), el
nuevo `PaymentAllocationRepository::sumAllocatedByCustomer()` y
`sumAvailableAdvancesByCustomer()`, que resta lo primero a lo segundo.

## Decisión

1. **Entidad puente `PaymentAllocation`** entre `Payment` y `Sale`, con
   `amount`, `created_at` y un índice único `(payment_id, sale_id)`. La
   unique hace que "aplicar dos veces el mismo anticipo a la misma venta"
   sea un error de base de datos, no solo una validación de dominio que
   alguien podría olvidar.
2. **`Payment.sale_id` se elimina.** No se mantiene como columna
   redundante: dos fuentes de verdad para el mismo vínculo se desincronizan
   en el primer bug de flush. `Sale::payments` pasa a ser `Sale::allocations`
   y `Payment::sale` deja de existir.
3. **El tenant no se guarda en la tabla puente.** Se resuelve por join con
   `payment.tenant`. Duplicarlo crearía una segunda columna que podría
   apuntar a un tenant distinto al del pago que la originó.
4. **`PaymentService::applyToSale()` es el único punto de escritura.**
   `register()` también crea allocations, pero por el mismo método privado
   `createAllocation()`: no hay dos formas de construir una.
5. **Un anticipo cubre cada venta UNA sola vez**, garantizado por la unique
   `(payment_id, sale_id)`. Es una regla de negocio, no solo de esquema:
   `applyToSale()` la consulta antes de construir la allocation
   (`PaymentAllocationRepository::hasAllocationForSale`) para que el doble
   submit del form salga como flash de error y no como violación de índice
   (500). La unique queda además como red de seguridad para cualquier
   entrada que no pase por el servicio.
6. **La validación de cliente es literal FIX-7**, idéntica a la de
   `register()`: se compara solo si el pago **y** la venta tienen cliente.
   Se descartó la variante estricta que exigía cliente en el pago, porque
   dejaría inválido un caso que el propio registro ya acepta.
7. **Sin `symfony/lock`, la sobre-asignación concurrente no está cerrada.**
   Dos requests que apliquen el mismo anticipo a la vez pasan ambas la
   validación de remanente y la segunda revienta contra
   `uniq_allocation_payment_sale` (500, no un mensaje de dominio). Queda
   documentado como límite conocido de la fase, no como omisión.

## Migración

`migrations/Version20261014151952.php` hace tres cosas en un solo
`up()`, en este orden porque cada una depende de la anterior:

1. **Crea `payment_allocation`** con sus índices y foreign keys.
2. **Copia los datos**: cada `payment.sale_id NOT NULL` se convierte en una
   allocation con `amount = payment.amount` (que era exactamente lo que
   significaba `sale_id`: el pago entero cubre esa venta) y
   `created_at = payment.created_at` (el momento en que el dinero se
   aplicó; la allocation no existía antes, así que su timestamp real es
   ese). Un pago apuntaba a una sola venta, así que la unique
   `(payment_id, sale_id)` no puede fallar.
3. **Elimina `sale_id`** con sus índices y su FK. Sin este paso quedarían
   dos representaciones del mismo hecho.

El `down()` es **aproximado y así se documenta**: restaura `sale_id` desde
la allocation de menor id de cada pago, porque el 1:N viejo no puede
representar un pago repartido en varias ventas. Perder las allocations
restantes es el costo de poder volver atrás, no un bug.

Se aplica con `doctrine:migrations:migrate`; ya está aplicada en el entorno
de desarrollo. No se usó `doctrine:schema:update` (ADR-0011 y las
convenciones del proyecto lo prohíben).

## Implementación

| Pieza | Archivo |
| --- | --- |
| Entidad puente | `src/Entity/PaymentAllocation.php` |
| Repositorio + interfaz | `src/Repository/PaymentAllocationRepository.php`, `src/Repository/Contract/PaymentAllocationRepositoryInterface.php` |
| Excepción de dominio | `src/Exception/Domain/PaymentAllocationException.php` |
| Servicio | `src/Service/Payment/PaymentService.php` (`applyToSale()`, `createAllocation()`) |
| DTO de lectura | `src/DTO/Query/AvailableAdvance.php` (ADR-0007: lectura va en `Query`) |
| Controller | `src/Controller/PaymentController.php` (`app_payment_apply_to_sale`, `app_payment_show`) |
| Templates | `templates/sale/show.html.twig`, `templates/payment/show.html.twig`, `templates/payment/index.html.twig` |
| Migración | `migrations/Version20261014151952.php` |

`PaymentService` no flushea (ADR-0017): `applyToSale()` lee dos agregados
SQL (remanente del pago, pendiente de la venta), construye la allocation y
la devuelve. El `flush()` único es del controller, igual que en `store()`.
La lectura es lo que obliga a que el controller flushee *entre* dos
aplicaciones consecutivas: sin ese flush, la segunda no vería la primera y
el techo de remanente no se activaría.

El form de aplicación se pinta solo cuando la venta es activa, tiene
cliente y ese cliente tiene anticipos con remanente. El controlador ya
filtra los tres casos; el `{% if %}` del template es redundante a propósito,
para no ofrecer una acción que el servidor rechazaría.

## Alternativas descartadas

| Alternativa | Por qué no |
| --- | --- |
| Mantener `sale_id` y agregar `sale_ids[]` (o una segunda columna) | Dos fuentes de verdad para el mismo vínculo; la primera divergencia aparece en el primer flush a medias. |
| `payment.sale_id` nullable + tabla puente opcional | Mismo problema, más silencioso: lo que esté escrito en la columna no coincidirá con la fila de la puente sin que nada lo señale. |
| Campo `allocated_amount` en `payment` (un solo total, sin fila por venta) | Resuelve "un anticipo en varias ventas" pero no "una venta con varios cobros", y no deja rastro de a qué venta se aplicó cuánto: el detalle de la venta tendría que adivinarlo. |
| Sumar allocations en `sumByCustomer()` | Cambiaría el significado del saldo del cliente de "dinero entrado" a "dinero aplicado" y rompería el reporte de saldo. Ver sección Problema. |
| Que `PaymentService` flushee la allocation | Rompería ADR-0017 (el servicio no flushea) y metería un punto de transacción en un método que solo valida y construye. |
| `symfony/lock` sobre el anticipo | Correcto y pendiente, pero fuera del alcance acordado para la fase; el índice único cubre el caso peor (datos correctos, error feo). |

## Consecuencias

- **Un pago sin allocations es un anticipo a cuenta.** El detalle de pago
  (`/pagos/{id}`) lo dice explícitamente en vez de dejar la tabla vacía
  sin explicación.
- **`SaleVoidService` no cambió**, y eso es la prueba de que el modelo es
  correcto: "venta con pagos" ahora se resuelve con `findBySale()`, que
  busca allocations. El guardia de "no se anula una venta cobrada" sigue
  funcionando sin tocar el servicio.
- **La anulación de una venta no libera el anticipo.** `void()` no borra
  allocations (eso dejaría dinero aplicado a nada); el caso queda fuera de
  alcance junto con los reembolsos.
- **El listado de pagos resuelve las ventas en una sola consulta**
  (`saleIdsByPaymentIds`), porque un join a la colección duplicaría cada
  fila de pago por cada allocation. Mismo motivo por el que el filtro por
  venta usa `EXISTS` y no un join.
- **Sin lock hay una carrera conocida.** Dos peticiones simultáneas sobre
  el mismo anticipo producen un 500 por violación de la unique, no un
  mensaje de negocio. Aceptado por ahora y anotado para cuando se agregue
  `symfony/lock`.

## Referencias

- `src/Entity/PaymentAllocation.php`
- `src/Service/Payment/PaymentService.php` (`register()`, `applyToSale()`)
- `src/Repository/PaymentAllocationRepository.php`
- `src/Repository/PaymentRepository.php` (`sumBySale`, `sumByCustomer`,
  `findAvailableAdvancesByCustomer`, `sumAvailableAdvancesByCustomer`)
- `src/Controller/PaymentController.php` (`applyToSale`, `show`)
- `migrations/Version20261014151952.php`
- `tests/Integration/Payment/PaymentAllocationServiceTest.php`
- `tests/Functional/Payment/PaymentAllocationTest.php`
- Depende de: `docs/adr/ADR-0005-profit-recognition-modes.md`,
  `docs/adr/ADR-0007-dto-location-convention.md`,
  `docs/adr/ADR-0011-unificacion-motor-postgresql.md`,
  `docs/adr/ADR-0015-login-global-con-seleccion-de-tenant.md`,
  `docs/adr/ADR-0017-flush-y-recompute-de-investment-summary.md`
