# Todo: Fase 1 UI

- [x] 1. ADR-0009 tenant por slug en login
- [x] 2. Instalar doctrine/doctrine-fixtures-bundle
- [x] 3. Fixtures (Tenant, AppUser, AppSetting, Product, Customer, UsdRate)
- [x] 4. AppUserProvider + AppUserChecker
- [x] 5. security.yaml (provider, form_login, logout, remember_me, access_control)
- [x] 6. SecurityController + login.html.twig
- [x] 7. Suite Functional + smoke tests de auth (TDD)
- [x] 8. base.html.twig (layout con nav, flashes, skip-link)
- [x] 9. DashboardController + dashboard/index.html.twig
- [x] 10. Smoke test del dashboard autenticado
- [x] 11. Accesibilidad sobre login y dashboard
- [x] 12. Verificación: lint:twig, lint:container, schema:validate, suite completa
- [x] 13. Revisión seguridad + code review + commit

## Checkpoint post-3
- [x] `doctrine:fixtures:load` sobre BD vacía OK
## Checkpoint post-7
- [x] Smoke auth verde
## Checkpoint post-9
- [x] Dashboard renderiza; suite completa verde
