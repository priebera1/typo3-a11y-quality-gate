<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Fixtures;

use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\AbstractWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;

/**
 * Test-only log writer: runs a callback once when the remote scan persistence logs its "raw results overview" line,
 * i.e. in the middle of storing a scan (or on another line of the store set in $message). Used to interleave a second reader deterministically (NEW-01).
 */
final class ReentrantPersistLogWriter extends AbstractWriter
{
    /** @var (callable():mixed)|null */
    public static $callback = null;
    public static int $fired = 0;
    public static string $message = 'AQG remote persist: raw results overview';

    public function writeLog(LogRecord $record): WriterInterface
    {
        if (self::$callback !== null && $record->getMessage() === self::$message) {
            $callback = self::$callback;
            self::$callback = null;
            self::$fired++;
            $callback();
        }

        return $this;
    }
}
