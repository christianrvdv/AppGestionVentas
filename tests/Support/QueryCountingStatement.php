<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;

/** @internal Parte del QueryCountingMiddleware; no usar fuera de ese flujo. */
final class QueryCountingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        StatementInterface $wrappedStatement,
        private readonly QueryCounter $counter,
        private readonly string $sql
    ) {
        parent::__construct($wrappedStatement);
    }

    public function execute(): Result
    {
        $this->counter->record($this->sql);

        return parent::execute();
    }
}
