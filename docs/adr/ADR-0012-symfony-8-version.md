# ADR-0012: Versión de Symfony 8.1 (confirmada y mantenida)

- **Estado:** Aceptado
- **Fecha:** 2026-10-08
- **Contexto:** Fase 1.5 (cierre de deuda técnica antes de Fase 3)

## Problema

No estaba documentada la versión exacta del framework en la que corre
el proyecto ni si había que planear una migración mayor antes de seguir
agregando features (Fase 3).

## Decisión

Confirmar con `bin/console about` que el proyecto corre sobre
**Symfony 8.1.8** (PHP 8.4.12) — `framework-bundle`, `security-bundle`
y `doctrine-bridge` en 8.1.8 — y **mantenerse en 8.1** sin planear
migración mayor antes de Fase 3.

Notas operativas:

- Symfony 8.1 no es LTS: el soporte de la rama 8.1 llega hasta
  01/2027 (soporte total de la rama 8.x hasta 07/2027). Conviene
  planear el salto a la siguiente versión estable dentro del ciclo de
  vida, pero eso queda fuera del alcance de esta fase.
- Toda la guía de convenciones (attributes `#[Route]`,
  `#[MapRequestPayload]`, `#[AsEventListener]`, `#[Autowire]`, etc.)
  aplica a esta versión; los patrones usados en Fases 1-2 son los
  canónicos de Symfony 8.

## Consecuencias

- Sin trabajo inmediato; la deuda es solo de calendario (seguir la
  cadencia de minor/major de Symfony en releases futuras).
