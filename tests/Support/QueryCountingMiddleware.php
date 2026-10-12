<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

/**
 * DBAL middleware que alimenta el QueryCounter con cada sentencia SQL.
 *
 * Registrado SOLO en el entorno test (config/services.yaml, bloque
 * when@test, tag doctrine.middleware). Va por fuera del StaticDriver de
 * DAMA (prioridad 0 < 100), así que envuelve la conexión estática que
 * cada boot del kernel devuelve y observa exactamente las sentencias del
 * test actual, sin tocar la lógica de transacciones de DAMA.
 */
final class QueryCountingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly QueryCounter $counter) {}

    public function wrap(Driver $driver): Driver
    {
        return new QueryCountingDriver($driver, $this->counter);
    }
}
