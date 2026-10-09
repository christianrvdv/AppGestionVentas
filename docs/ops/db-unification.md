# Unificación del motor de BD a PostgreSQL

Contexto: ADR-0011. Dev, test y prod corren sobre PostgreSQL. La suite
de tests ya **no** fuerza SQLite (`phpunit.dist.xml` dejó de fijar
`DATABASE_URL`); cada máquina configura sus bases y sus URLs locales.

## 1. Requisitos

- PostgreSQL 14+ instalado y corriendo (probado con 18.4).
- Extensión PHP `pdo_pgsql` habilitada (`php -m | findstr pgsql`).

## 2. Crear las bases (una vez por máquina)

```sql
CREATE DATABASE app_control_ventas;
CREATE DATABASE app_control_ventas_test;
```

## 3. URLs de conexión (por entorno)

Como `.env` y `.env.test` **no están trackeados** (cada dev tiene los
suyos), agregá estas claves en tu `.env` y `.env.test` con tus
credenciales locales:

```
# .env (dev)
DATABASE_URL="postgresql://USER:PASS@127.0.0.1:5432/app_control_ventas?serverVersion=14&charset=utf8"
```

```
# .env.test (tests)
DATABASE_URL="postgresql://USER:PASS@127.0.0.1:5432/app_control_ventas_test?serverVersion=14&charset=utf8"
```

Cuidado con el url-encoding de la contraseña en la URL
(p. ej. `/` → `%2F`, `*` → `%2A`).

## 4. Esquema en dev

La BD de test **no** necesita migraciones: cada test dropea todo con
`DROP TABLE ... CASCADE` y recrea el esquema desde los metadatos de
Doctrine (`createSchema()`).

En dev, el esquema se crea/actualiza con migraciones:

```bash
php bin\console doctrine:migrations:migrate
```

o, en un setup recién creado sin migraciones históricas, validando
contra los metadatos:

```bash
php bin\console doctrine:schema:validate
```

## 5. Verificación

```bash
php bin\console doctrine:schema:validate   # mapping + BD en verde
php bin\phpunit                             # 138 tests sobre PostgreSQL
```

Si `php bin\phpunit` falla con errores de conexión, revisá que la base
`app_control_ventas_test` exista (paso 2) y que `.env.test` tenga la
URL correcta.

## Notas

- La suite tarda ~1 min 20 s sobre PostgreSQL (vs ~18 s con SQLite):
  cada test recrea el esquema completo. Es el costo asumido de validar
  el motor real; si vuelve pesada, considerar activar la extensión
  PHPUnit de `dama/doctrine-test-bundle` (rollback transaccional por
  test) en `phpunit.dist.xml`.
- Los decimales se comparan con `assertDecimalEquals()` (bccomp,
  precisión 2) para no depender de cómo cada motor devuelve NUMERIC.
