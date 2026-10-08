# Plan: Fase 2 UI — Inversiones

Spec: docs/spec/SPEC-web-inversiones-fase2.md · ADR-0010 · DoD: suite completa verde + lints + commit `feat(web): CRUD de inversiones, paginación y rate limiting de login (Fase 2 UI)`.

## Pasos

1. **Dependencias**: `composer require knplabs/knp-paginator-bundle symfony/rate-limiter`; verificar recipe y `config/packages/knp_paginator.yaml`; añadir `login_throttling` al firewall main; test de throttling.
2. **Repositorio (aditivo)**: `InvestmentRepositoryInterface::createPaginatedQueryBuilder(int, ?string): QueryBuilder` + implementación.
3. **Form types**: `InvestmentTypeType` (fecha ≤ hoy, descripción ≤255), `InvestmentItemType` (producto, cantidad ≥1, costo ≥0), `InvestmentCancelTypeType` (motivo 1-255). CSRF token_id `submit`.
4. **InvestmentController**: index (paginate + filtro status), show (findByIdAndTenant + colecciones), new, edit (guard OPEN !confirmed), addItem, confirm, cancel. Captura de excepciones de dominio → flash. Roles: acceso autenticado (ROLE_USER).
5. **Templates**: `templates/investment/{index,show,new,edit}.twig` + partials; nav link real en base.html.twig con aria-current; dashboard enlaza al detalle.
6. **Tests TDD** (tests/Functional/Investment/): index paginado con >12 inversiones; filtro; show 200; show ajeno 404; new ok/inválido; edit ok/guard; addItem ok (totales)/inválido; confirm ok/sin ítems flash; cancel ok/motivo/ya cancelada flash; login throttling (5 fallos + 6.º correcto no autentica, limiter limpio por test).
7. **DoD**: suite completa; lint:twig; lint:container; schema:validate sin cambios; tailwind:build si hace falta.
8. **Revisión** + commit.

## Checkpoints
- Post-1: login_throttling activo; throttling test verde.
- Post-5: index/show/new renderizan en dev con fixtures demo.
- Post-6: smoke tests de la fase verdes.
- Post-7: 117+ tests dominio+funcionales verdes.
