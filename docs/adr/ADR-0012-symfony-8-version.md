# ADR-0012: Versión de Symfony 8.1 y plan de upgrade

- **Estado:** Aceptado
- **Fecha:** 2026-10-08 (plan de upgrade agregado el 2026-10-09, Fase 1.5b)
- **Contexto:** Fase 1.5 (cierre de deuda técnica antes de Fase 3)

## Problema

No estaba documentada la versión exacta del framework en la que corre
el proyecto ni si había que planear una migración mayor antes de seguir
agregando features (Fase 3). Tampoco había un plan para cuando 8.1
quede sin soporte.

## Decisión

Confirmar con `bin/console about` que el proyecto corre sobre
**Symfony 8.1.8** (PHP 8.4.12) — `framework-bundle`, `security-bundle`
y `doctrine-bridge` en 8.1.8 — y **mantenerse en 8.1 hasta el hito de
upgrade** descrito más abajo, sin planear migración mayor antes de
Fase 3.

Toda la guía de convenciones (attributes `#[Route]`,
`#[MapRequestPayload]`, `#[AsEventListener]`, `#[Autowire]`, etc.)
aplica a esta versión; los patrones usados en Fases 1-2 son los
canónicos de Symfony 8.

## Calendario verificado (symfony.com/releases, 2026-10-09)

| Rama | Sale | LTS | Soporte de bugs hasta | Soporte de seguridad hasta |
|------|------|-----|------------------------|-----------------------------|
| 8.0  | 11/2025 | no | 07/2026 (vencida) | 07/2026 (vencida) |
| **8.1 (actual)** | 05/2026 | no | **01/2027** | **01/2027** |
| 8.2  | 11/2026 | no | 07/2027 | 07/2027 |
| 8.3  | 05/2027 | no | 01/2028 | 01/2028 |
| **8.4** | **11/2027** | **sí** | 11/2030 | 11/2031 |
| 7.4  | 11/2025 | sí | 11/2028 | 11/2029 |

Datos clave: **versión actual 8.1.8**, **EOL de 8.1: 2027-01-31**
(coincide con `bin/console about`: "End of life 01/2027").

Corrección respecto de la versión previa de este ADR: en Symfony las
ramas `x.4` son las LTS (6.4, 7.4 → **8.4**), y **8.4 recién sale en
11/2027**. Por lo tanto "migrar a 8.4 antes de Q1 2027" no es
posible; el plan se separa en dos hitos.

## Plan de upgrade (dos hitos)

### Hito 1 — salir de 8.1 antes de Q1 2027 (obligatorio)

- **Objetivo:** 8.1 → **8.2** (sale 11/2026), para no operar sobre una
  rama vencida. 8.1 muere el **31/01/2027**.
- **Ventana estimada:** diciembre 2026, es decir **antes de Q1 2027**.
- **Dónde:** en un chat propio, **antes de Fase 5** (Fase 3 y 4 siguen
  sobre 8.1; es una rama minor dentro del mismo mayor, riesgo bajo).
- **Qué toca:** subir `extra.symfony.require` y los `require` de
  `symfony/*` de `8.1.*` a `8.2.*` en `composer.json`, `composer update`,
  y correr la suite completa + `bin/console about`.

### Hito 2 — llegar al LTS 8.4

- **Objetivo:** **8.4 LTS** (sale 11/2027, EOL 11/2031): 3 años de
  soporte de bugs y 4 de seguridad.
- **Camino:** 8.2 → 8.3 (05/2027) → 8.4 LTS (11/2027), un minor por
  vez; nunca saltear de un golpe.
- **Cuándo:** chat aparte, durante 2027; fuera del alcance de las
  fases actuales.

### Alternativa descartada

Retroceder a **7.4 LTS** (soporte hasta 11/2028) para tener LTS hoy:
implica bajar de mayor, rehacer los `remove` hechos en 8.0 y perder
las APIs nuevas de 8.x. Solo tendría sentido si 8.2 no estuviera
disponible antes del 31/01/2027.

## Consecuencias

- Trabajo inmediato en Fase 1.5b: solo documentar; la migración del
  hito 1 es una tarea aparte con su propio chat.
- El calendario es la deuda real: si el hito 1 se pospone más allá de
  enero 2027 el proyecto queda sin soporte de seguridad de framework.
- Cada minor trae deprecaciones; subir de a minor (hito 1, hito 2) es
  más barato que acumularlas hasta 8.4.
