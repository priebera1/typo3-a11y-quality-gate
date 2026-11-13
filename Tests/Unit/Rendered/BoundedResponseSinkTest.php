<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Rendered\BoundedResponseSink;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BoundedResponseSinkTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function writesUpToTheLimitAreStoredExactly(): void
    {
        $sink = new BoundedResponseSink(10);
        self::assertSame(6, $sink->write('<html>'));
        self::assertSame(4, $sink->write('abcd'));
        self::assertFalse($sink->exceeded());
        self::assertSame(10, $sink->bytes());
        $sink->rewind();
        self::assertSame('<html>abcd', $sink->getContents());
    }

    #[Test]
    public function theWriteThatWouldCrossTheLimitIsRefusedAndNothingAfterIt(): void
    {
        $sink = new BoundedResponseSink(10);
        self::assertSame(8, $sink->write('12345678'));
        self::assertSame(0, $sink->write('9ab'), 'A short write makes cURL abort the transfer.');
        self::assertTrue($sink->exceeded());
        self::assertSame(0, $sink->write('c'), 'Once exceeded, nothing more is accepted.');
        self::assertFalse($sink->isWritable());
        self::assertSame(8, $sink->bytes());
        self::assertSame('12345678', (string)$sink);
    }
}
