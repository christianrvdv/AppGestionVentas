# ADR-0011: Motor de BD unificado en PostgreSQL (dev, test y prod)

- **Estado:** Aceptado
- **Fecha:** 2026-10-08
- **Contexto:** Fase 1.5 (cierre de deuda técnica antes de Fase 3)

## Problema

El proyecto tenía el motor fragmentado:

- **prod** corría sobre PostgreSQL (la URL original de `DATABASE_URL`).
- **dev** tenía los `.env` locales apuntando a PostgreSQL, pero era
  fácil caer en SQLite sin darse cuenta.
- **test** estaba **anclado a SQLite in-memory** por fuerza explícita en
  `phpunit.dist.xml` (`force="true"`), lo que hacía que la suite pasara
  sobre un motor distinto del de producción.

Consecuencias concretas de esa fragmentación (algunas ya se habían
pagado a mano y otras descubiertas al migrar la suite):

- Diferencias de comportamiento entre motores: `DECIMAL` devuelve
  `'250.5'` en SQLite (afinidad NUMERIC sin escala) pero `'250.50'` en
  PostgreSQL; la suite tenía aserciones ajustadas al comportamiento de
  SQLite (`assertSame('250.5', ...)`).
- El borrado de tablas entre tests no necesitaba `CASCADE` en SQLite;
  en PostgreSQL las FKs entre tablas lo exigen (el orden alfabético de
  `listTableNames()` no respeta dependencias).
- Riesgo de que un cambio validado en CI/dev no se comporte igual en
  prod (funciones, tipos, comparaciones, manejo de transacciones).

## Decisión

**Unificar dev, test y prod en PostgreSQL.** La suite de tests corre
contra la base `app_control_ventas_test` de PostgreSQL 18 (igual que
dev), y se elimina el override de SQLite de `phpunit.dist.xml`.

Detalles de la implementación:

- `phpunit.dist.xml` ya no fija `DATABASE_URL`; la URL de test viene de
  `.env.test` (que, como `.env`, es un archivo local por dev, no
  trackeado). Ver `docs/ops/db-unification.md` para el setup manual de
  cada máquina.
- Ambos `TestCase` recrean el esquema por test con
  `DROP TABLE IF EXISTS ... CASCADE` + `createSchema()` (antes:
  `dropTable()` sin cascade). Sigue sin haber rollback automático (la
  extensión PHPUnit de DAMA no está activa); la recreación por test
  además valida que el mapping completo sea creable.
- Las aserciones de decimales usan `assertDecimalEquals()` (bccomp con
  precisión 2), que es válida en cualquier motor.
- `doctrine:schema:validate` queda en verde contra el esquema dev de
  PostgreSQL (mapping y BD sincronizados).

## Alternativas descartadas

- **Mantener SQLite en test y confiar en la similitud:** la suite
  seguiría validando un motor distinto del que corre en prod; ya
  produjo aserciones frágiles y comportamiento de FKs distinto.
- **Testear con SQLite + pg_compat o fixtures específicas:** no cubre
  las diferencias reales de tipos/SQL.

## Consecuencias

- La suite en PostgreSQL tarda ~1 min 20 s (vs ~18 s en SQLite) por
  recrear el esquema por test; se asume como costo de validar el motor
  real. Si se vuelve pesada, el próximo paso natural es activar la
  extensión PHPUnit de DAMA (rollback transaccional por test).
- Cada dev necesita PostgreSQL local y las bases `app_control_ventas` y
  `app_control_ventas_test`, más los `DATABASE_URL` en su `.env` /
  `.env.test` (no trackeados, ver guía en `docs/ops/db-unification.md`).
- Futuras migraciones y fixtures se prueban contra el motor real.
