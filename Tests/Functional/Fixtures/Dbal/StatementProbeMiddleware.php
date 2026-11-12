<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Fixtures\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Test-only Doctrine driver middleware: after every statement the default TYPO3 connection executed, $afterStatement is
 * called with its SQL. A test uses it to run another database session's writes at an exact point between two statements
 * of the code under test (deterministic interleaving without any hook in production code). Never registered outside
 * functional tests.
 */
final class StatementProbeMiddleware implements Middleware
{
    /** @var (\Closure(string):void)|null */
    public static ?\Closure $afterStatement = null;

    /** @var list<string> SQL of every statement since the last reset, for assertions about statement order */
    public static array $log = [];

    public static bool $recording = false;

    public function wrap(Driver $driver): Driver
    {
        return new StatementProbeDriver($driver);
    }

    public static function reset(): void
    {
        self::$afterStatement = null;
        self::$log = [];
        self::$recording = false;
    }

    public static function executed(string $sql): void
    {
        if (self::$recording) {
            self::$log[] = $sql;
        }
        $probe = self::$afterStatement;
        if ($probe !== null) {
            $probe($sql);
        }
    }
}
