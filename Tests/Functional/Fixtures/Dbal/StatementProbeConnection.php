<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Fixtures\Dbal;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @internal see StatementProbeMiddleware */
final class StatementProbeConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        return new StatementProbeStatement(parent::prepare($sql), $sql);
    }

    public function query(string $sql): Result
    {
        $result = parent::query($sql);
        StatementProbeMiddleware::executed($sql);

        return $result;
    }

    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        StatementProbeMiddleware::executed($sql);

        return $result;
    }
}
