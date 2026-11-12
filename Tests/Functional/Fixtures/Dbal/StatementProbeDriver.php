<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Fixtures\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** @internal see StatementProbeMiddleware */
final class StatementProbeDriver extends AbstractDriverMiddleware
{
    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        return new StatementProbeConnection(parent::connect($params));
    }
}
