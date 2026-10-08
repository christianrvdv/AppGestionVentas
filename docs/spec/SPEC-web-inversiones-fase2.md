# Spec: Web Inversiones (Fase 2 UI)

## Objetivo
CRUD web de inversiones sobre el dominio existente: listado paginado, detalle
completo, creación de cabecera, edición de cabecera (borrador), agregado de
ítems, confirmación y cancelación. Activar el nav link de Inversiones y el
enlace de detalle desde el dashboard. Rate limiting de login (pendiente
documentado de Fase 1).

Usuario: operador de negocio autenticado (ROLE_USER). Éxito: crea una inversión
desde la UI, agrega ítems con productos existentes, la confirma y ve los
totales materializados; el listado pagina y filtra por estado; el login quedó
protegido contra fuerza bruta.

## Fuera de alcance (no tocar)
- CRUD de Ventas, Productos, Clientes, Reportes, Settings y tasas.
- Registro de pagos (PaymentService) y gastos de inversión (UI de
  InvestmentExpenseService): sus entidades se muestran en SOLO LECTURA si
  existen; la escritura queda para Fase 3.
- Cierre manual de inversión (StateMachine::close) y ventas/recuperación.
- Turbo Frames/Streams; exportaciones; búsqueda full-text.
- Modificación de entidades/servicios del dominio existentes (solo adiciones
  puntuales, cada una justificada abajo).

## Decisiones fijadas
- Roles: inversiones es ROLE_USER (el operador las crea/edita). ROLE_ADMIN
  se reserva para settings y tasas (Fase 1).
- Crear = solo cabecera (fecha + descripción) vía
  `InvestmentCreationService::createDraft()`; el código INV-YYYYMMDD-NNN, la
  moneda base y la tasa USD snapshot los resuelve el dominio. Los ítems se
  agregan desde el detalle con `InvestmentItemService::addItem()`. Ver ADR-0010.
- Editar = descripción y fecha, solo si `status === OPEN` e `isConfirmed()`
  es falso. Confirmadas/parciales/cerradas/canceladas son de solo lectura.
- Confirmar e Cancelar son acciones POST desde el detalle (botón + CSRF),
  delegando a `InvestmentConfirmationService::confirm()` y
  `InvestmentStateMachine::cancel()` respectivamente. Confirmar requiere ≥ 1
  ítem (lo valida el dominio; el controller lo captura como flash de error).
- Cancelar pide motivo (requerido por el dominio), en un form propio.
- Paginación: `knplabs/knp-paginator-bundle`, 10 por página, orden
  `investmentDate DESC, code DESC`, filtro opcional por estado via query
  param `status` (valores: OPEN/PARTIAL/RECOVERED/CLOSED/CANCELLED o vacío).
  Traducción de etiquetas al español.
- Extensión aditiva del puerto: se añade
  `InvestmentRepositoryInterface::createPaginatedQueryBuilder(int $tenantId,
  ?string $status): QueryBuilder` (implementación en InvestmentRepository
  con `andWhere` explícito sobre tenant — no depende del SQLFilter). Ningún
  método existente cambia de firma.
- Rate limiting: `composer require symfony/rate-limiter` y opción
  `login_throttling: { max_attempts: 5, interval: 5 minutes }` en el firewall
  `main`. Sin código propio: el LoginThrottlingListener del SecurityBundle
  ya emite `TooManyLoginAttemptsAuthenticationException` que form_login
  muestra como error de login genérico. Verificado en vendor
  (LoginThrottlingFactory).
- El detalle muestra ítems, gastos y ventas en solo lectura (via
  colecciones de la entidad). Los pagos cuelgan de Sale (y los anticipos
  no vinculan inversión): su UI es de Fase 3 junto con Ventas.
- El dashboard y el listado enlazan al detalle por id; los controllers
  resuelven siempre por `findByIdAndTenant()` con el TenantContext de la
  sesión (404 si no existe o pertenece a otro tenant).

## Alcance y criterios de aceptación

### 1. Paginación y repositorio
- [ ] `InvestmentRepositoryInterface` + `InvestmentRepository`:
      `createPaginatedQueryBuilder(tenantId, ?status)` filtra por tenant y,
      si se pasa status, por estado; ordena por fecha DESC y code DESC.
- [ ] knp-paginator instalado; controller usa `PaginatorInterface::paginate()`.

### 2. Listado `/inversiones` (GET)
- [ ] Tabla: código, fecha, estado (badge), descripción, total inversión,
      link "Ver detalle". Estado CANCELLED/CLOSED con estilo apagado.
- [ ] Filtro por estado (select + botón "Filtrar"; opción "Todos" limpia).
- [ ] Paginación con `knp_pagination_render` (etiquetas en español,
      "Anterior/Siguiente"), estado del filtro preservado en los links.
- [ ] Botón "Nueva inversión" hacia `/inversiones/nueva`.
- [ ] Vacío: mensaje amigable + link a crear la primera.

### 3. Crear `/inversiones/nueva` (GET/POST)
- [ ] Form: fecha (date, requerida, por defecto hoy), descripción (texto,
      opcional, máx 255). CSRF token_id `submit`.
- [ ] POST válido → `createDraft()` + persist + flash "Inversión creada" +
      redirect al detalle (302). POST inválido → re-render con errores.
- [ ] Validación: fecha no futura (Assert\LessThanOrEqual "today").

### 4. Detalle `/inversiones/{id}` (GET)
- [ ] Cabecera: código, fecha, estado, descripción, moneda base, tasa
      snapshot, totales (mercadería, gastos, inversión total), confirmada el.
- [ ] Tabla de ítems: producto, cantidad, costo unitario, costo real,
      precio sugerido; si no hay ítems, mensaje "Sin ítems".
- [ ] Tabla de gastos (solo lectura; vacío OK) y de ventas asociadas (solo
      lectura; los pagos cuelgan de Sale, no de Investment — ver nota).
- [ ] Acciones contextuales (solo si status === OPEN e !isConfirmed()):
      form "Agregar ítem" (select de productos activos del tenant vía
      `ProductRepositoryInterface::findActiveByTenant`, cantidad > 0, costo
      unitario ≥ 0 numérico) y botón "Confirmar inversión" (deshabilitado si
      no hay ítems, con aria-disabled y texto explicativo) y "Cancelar
      inversión" que abre el form de motivo.
- [ ] Si está OPEN pero confirmada o en otro estado: solo lectura de
      acciones (no se muestran los forms).
- [ ] Inversión de otro tenant o inexistente → 404.

### 5. Editar `/inversiones/{id}/editar` (GET/POST)
- [ ] Mismo form que crear (fecha + descripción). Solo accesible si OPEN y
      no confirmada; si no, 404 o redirect con flash según proceda.
- [ ] POST válido → flush + flash "Inversión actualizada" + redirect detalle.
- [ ] El código, los totales y la tasa NO se editan desde la UI.

### 6. Agregar ítem (POST `/inversiones/{id}/items`)
- [ ] `addItem()` vía InvestmentItemService (valida OPEN, tenant del
      producto, quantity > 0, unitCost ≥ 0). Éxito: flash + redirect detalle.
- [ ] Errores de dominio (producto de otro tenant, inversión confirmada):
      flash de error + redirect detalle, sin 500.
- [ ] Validación de form: cantidad entera ≥ 1, costo decimal ≥ 0, producto
      requerido.

### 7. Confirmar (POST `/inversiones/{id}/confirmar`)
- [ ] `confirm()` + flush único. Éxito: flash "Inversión confirmada" +
      redirect detalle; totales materializados visibles.
- [ ] Sin ítems o ya confirmada: el dominio lanza; el controller captura
      (LogicException / InvestmentAlreadyConfirmedException) y responde con
      flash de error + redirect, sin 500.

### 8. Cancelar (POST `/inversiones/{id}/cancelar`)
- [ ] Form con motivo (requerido, 1-255). `cancel()` + flush. Éxito: flash
      + redirect detalle; estado CANCELLED con motivo visible.
- [ ] Transiciones inválidas (CANCELLED/CLOSED): flash de error + redirect.

### 9. Navegación
- [ ] `base.html.twig`: nav "Inversiones" pasa de placeholder a link real
      (`app_investment_index`), con aria-current cuando está activo.
- [ ] `dashboard/index.html.twig`: cada fila de inversión activa enlaza a su
      detalle (el placeholder "Detalle (próximamente)" desaparece).

### 10. Rate limiting de login
- [ ] `symfony/rate-limiter` instalado; `login_throttling` en el firewall
      `main` (5 intentos / 5 minutos).
- [ ] 5 intentos fallidos consecutivos con credenciales inválidas → el 6.º
      (aunque la contraseña sea correcta) recibe error de login (no 500);
      el mensaje sigue siendo genérico (sin enumerar).
- [ ] Un login exitoso reinicia el contador (listener del bundle).
- [ ] Test determinista: se limpia la caché del limiter
      (`cache.rate_limiter`) entre tests para evitar contaminación.

## Comandos
- Tests: `php bin\phpunit` (suite completa debe quedar verde).
- Lint: `php bin\console lint:twig templates/`, `lint:container`.
- Esquema: `doctrine:schema:validate` (NO debe cambiar: no hay migraciones).
- Assets: `php bin\console tailwind:build` si se tocan tokens/clases.

## Estrategia de tests
Functional (WebTestCase, misma base de Fase 1: FunctionalTestCase con
recreateSchema + seed + login real). Un test por flujo de esta spec:
índice 200+paginado (>10 inversiones creadas con InvestmentCreationService),
filtro por estado, detalle 200, detalle ajeno 404, crear ok (redirect+
persiste con código generado), crear inválido (fecha futura), editar ok,
editar en confirmada no permitido, agregar ítem ok (totales se recalculan),
agregar ítem inválido (cantidad 0), confirmar ok (estado + summary),
confirmar sin ítems → flash error, cancelar ok (motivo persistido),
cancelar ya cancelada → flash error. Rate limiting: 5 POSTs fallidos y el
6.º con credenciales correctas NO autentica. La suite de dominio existente
(111 tests) no se toca.

## Límites
- Siempre: tests antes de dar por hecho; textos en español; contraste AA;
  CSRF en todo POST; sin lógica de negocio en Twig (el controller delega en
  servicios del dominio y captura sus excepciones).
- Preguntar antes: tocar entidades/repos/servicios del dominio existentes
  (la única adición planificada es el método de paginación, aditivo y sin
  cambio de firma); migraciones (no debería hacer falta ninguna).
- Nunca: secrets commiteados; tocar ADR-0008/OBS-3; borrar tests existentes;
  exponer datos de otro tenant (siempre findByIdAndTenant / TenantContext).

## Criterios de aceptación globales (DoD)
Listado paginado y filtrable funciona sobre datos reales del tenant;
detalle muestra ítems/gastos/pagos; crear→agregar ítem→confirmar deja la
inversión con totales correctos; cancelar deja CANCELLED con motivo; el
nav y el dashboard enlazan al detalle; el login resiste fuerza bruta; smoke
tests de la fase verdes; suite completa, lint:twig, lint:container y
schema:validate OK; ADR-0010 commiteado; un commit
`feat(web): CRUD de inversiones, paginación y rate limiting de login (Fase 2 UI)`.

## Preguntas abiertas
Ninguna.
