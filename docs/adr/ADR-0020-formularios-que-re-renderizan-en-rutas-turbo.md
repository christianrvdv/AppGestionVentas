# ADR-0020: Formularios que re-renderizan en rutas Turbo

- **Estado:** Aceptado.
- **Fecha:** 2026-10-14.
- **Contexto:** la app pinta formularios y navega con Hotwire Turbo
  Drive. Turbo intercepta el submit de un `<form>` y exige una
  redirección en la respuesta; un render directo de un form inválido
  (200 por defecto de Symfony) rompe la navegación. Este ADR fija el
  patrón único para re-renderizar formularios inválidos sin romper
  Turbo.

## Problema

Turbo Drive intercepta el submit de un `<form>`. Si la respuesta es 2xx
sin cabecera `Location`, Turbo lanza "Form responses must redirect to
another location". Symfony, en cambio, devuelve 200 por defecto al
re-renderizar un form inválido, así que el patrón habitual

```php
if ($form->isSubmitted() && $form->isValid()) {
    // guardar + redirect
}

return $this->render('.../new.html.twig', ['form' => $form->createView()]);
```

revienta en Turbo: en vez del form con sus errores, el usuario ve la
página de error del framework.

El problema se manifestó tres veces seguidas, cada vez en una ruta
distinta, antes de que quedara fijado el patrón (commits `fc4f911`,
`6248663` y `cee0e1d`).

Hay un segundo caso más sutil: el form pasa la validación de campos pero
el dominio rechaza la operación (`DomainException`). Si el flujo
re-renderiza ahí con 200, Turbo revienta igual. Redirigir con
`addFlash` es compatible con Turbo, pero el mensaje queda despegado del
form y el usuario pierde lo que había escrito en el formulario.

## Decisión

1. **Toda ruta que re-renderice un formulario inválido pasa el
   `FormInterface` directamente a `render()`, no `$form->createView()`.**
   Con el `FormInterface`, `AbstractController::render()` fija 422
   automáticamente cuando el form está enviado e inválido; Turbo
   re-renderiza la página con los errores en su sitio en vez de exigir
   una redirección.

2. **Un rechazo del dominio con form válido se convierte en `FormError`
   en la raíz del form** (`$form->addError(new FormError($e->getMessage()))`)
   cuando el flujo termina en re-render. El form queda inválido → 422 →
   Turbo re-renderiza y el mensaje viaja pegado al form. En este camino
   no se usa `addFlash`: el flash se despega del formulario.

3. **Si la plantilla puede recibir errores de raíz, los pinta.** El
   referente es `templates/usd_rate/new.html.twig`:

   ```twig
   {% for error in form.vars.errors %}
       <p class="...">{{ error.message }}</p>
   {% endfor %}
   ```

   Sin ese bloque, el error de raíz viaja en la respuesta 422 pero no se
   ve. Los controllers que solo añaden errores por campo (Onboarding en
   `slug`, PasswordChange en `currentPassword`/`confirmPassword`) no
   necesitan el bloque: sus errores ya salen con `form_errors` de cada
   campo.

## Excepciones

- **POST puro que siempre redirige** (`SaleController::void`,
  `AdminTenantController::toggleActive`, `AdminUserController::toggleActive`,
  `InvestmentController::addItem`, etc.): no re-renderizan form, siempre
  redirigen. Turbo no se entera, no aplica el patrón.
- **Rechazo de dominio que redirige con flash** a una página que puede
  alojar el mensaje (`InvestmentController::addExpense` → show): el
  redirect es Turbo-compatible; la decisión 2 es obligatoria solo cuando
  el flujo re-renderiza.
- **Render en GET con `createView()`** (`SettingsController::index`,
  formularios secundarios del show de inversión): un GET no es un submit
  de form; el 200 es correcto y Turbo no intercepta nada.

## Consecuencias

- Los tests funcionales de form inválido asertan **422**, no 200
  (`CustomerCrudTest`, `ProductCrudTest`, `InvestmentCrudTest`,
  `InvestmentExpenseUiTest`, `PaymentRegistrationTest`,
  `SaleRegistrationTest`, `UsdRateControllerTest`,
  `AdminPasswordChangeTest`).
- Una ruta que devuelve 200 con un form inválido en un escenario Turbo es
  un bug, no un estilo de código.
- `tests/Functional/Report/ReportPagesTest.php` más los tests
  funcionales de cada CRUD cubren la regresión.
- Un form cuyo controller pueda añadir errores de raíz necesita el bloque
  de la decisión 3 en su plantilla; añadir una ruta nueva sin ese bloque
  es deuda visible.

## Anexo — formularios migrados

Rutas que ya cumplen la decisión (lista cerrada al momento de escribir
este ADR, confirmada contra el código):

- Customer: new, edit
- Product: new, edit
- Investment: new, edit, addExpense (re-render del form de gastos en
  show)
- Payment: store → renderNew
- Sale: create → renderNew
- Onboarding: __invoke (error de dominio en el campo slug)
- PasswordChange: __invoke (errores por campo)
- Settings: save (index en GET conserva `createView`)
- UsdRate: new (error de dominio en la raíz + bloque en la plantilla)

## Referencias

- ADR-0004 — Correcciones de tasa USD: motivo del test que dobla
  `UsdRateService` en `UsdRateController::new`
  (`UsdRateControllerTest::testNewDomainFailureReturns422WithDomainMessage`).
- ADR-0019 — Impersonation de tenant (ADR inmediatamente anterior).

## Deuda conocida (no urgente, no bloquea)

`UsdRateService::setRate()` tipa `string $rate`. La normalización vive en
el controller. Si otra ruta llama a `setRate()` (comando de consola,
import, API), repetirá el `TypeError`. Mover la normalización al borde
del dominio sería más robusto, pero queda fuera del alcance de este ADR.
