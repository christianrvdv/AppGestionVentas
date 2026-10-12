<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal Parte del QueryCountingMiddleware; no usar fuera de ese flujo. */
final class QueryCountingConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $wrappedConnection, private readonly QueryCounter $counter)
    {
        parent::__construct($wrappedConnection);
    }

    public function prepare(string $sql): Statement
    {
        return new QueryCountingStatement(parent::prepare($sql), $this->counter, $sql);
    }

    public function query(string $sql): Result
    {
        $this->counter->record($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->counter->record($sql);

        return parent::exec($sql);
    }
}
