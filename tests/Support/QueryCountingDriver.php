<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** @internal Parte del QueryCountingMiddleware; no usar fuera de ese flujo. */
final class QueryCountingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver, private readonly QueryCounter $counter)
    {
        parent::__construct($wrappedDriver);
    }

    public function connect(array $params): DriverConnection
    {
        return new QueryCountingConnection(parent::connect($params), $this->counter);
    }
}
