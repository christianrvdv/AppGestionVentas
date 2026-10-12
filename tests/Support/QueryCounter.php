<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Acumula las sentencias SQL ejecutadas en la request actual (Fase 7.5).
 *
 * Lo alimenta QueryCountingMiddleware (DBAL middleware registrado solo en
 * el entorno test, ver config/services.yaml when@test). Cada test kernel
 * crea un contenedor nuevo, así que el contador arranca vacío y no hay
 * estado entre tests.
 *
 * Uso típico: regresiones de N+1 — contar cuántas veces aparece una tabla
 * concreta en el SQL durante una operación.
 */
final class QueryCounter
{
    /** @var list<string> */
    private array $queries = [];

    public function record(string $sql): void
    {
        $this->queries[] = $sql;
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    /**
     * @return list<string>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * Cantidad de sentencias cuyo SQL contiene el substring dado.
     */
    public function countContaining(string $needle): int
    {
        $count = 0;
        foreach ($this->queries as $sql) {
            if (stripos($sql, $needle) !== false) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Sentencias cuyo SQL contiene el substring dado (para mensajes de
     * fallo útiles: se imprime el SQL inesperado).
     *
     * @return list<string>
     */
    public function containing(string $needle): array
    {
        $matches = [];
        foreach ($this->queries as $sql) {
            if (stripos($sql, $needle) !== false) {
                $matches[] = $sql;
            }
        }

        return $matches;
    }
}
