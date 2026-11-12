<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Fixtures\Dbal;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal see StatementProbeMiddleware */
final class StatementProbeStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $wrapped, private readonly string $sql)
    {
        parent::__construct($wrapped);
    }

    public function execute(): Result
    {
        $result = parent::execute();
        StatementProbeMiddleware::executed($this->sql);

        return $result;
    }
}
