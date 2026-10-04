<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanSubmissionTracker;
use TYPO3\CMS\Core\Registry;

final class RemoteScanSubmissionTrackerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $entries = [];

    #[Test]
    public function aSubmitIsPendingFromBeginToEndPerSite(): void
    {
        $tracker = new RemoteScanSubmissionTracker($this->registry());

        $tracker->begin('main', 'page', 42, 1);
        $pending = $tracker->findPending('main');

        self::assertIsArray($pending);
        self::assertSame(['page', 42, 1], [$pending['scope'], $pending['pageUid'], $pending['languageUid']]);
        self::assertNull($tracker->findPending('other'), 'each site has its own submit');

        $tracker->end('main');
        self::assertNull($tracker->findPending('main'));
    }

    #[Test]
    public function anEntryLeftByARequestThatDiedStopsCounting(): void
    {
        $tracker = new RemoteScanSubmissionTracker($this->registry());
        $tracker->begin('main', 'site', 0, -1);
        $key = array_key_first($this->entries);
        self::assertIsString($key);
        $this->entries[$key]['startedAt'] = time() - 121;

        self::assertNull($tracker->findPending('main'));
    }

    #[Test]
    public function aSiteWithoutIdentifierIsNeverTracked(): void
    {
        $tracker = new RemoteScanSubmissionTracker($this->registry());
        $tracker->begin('', 'page', 42, 0);

        self::assertSame([], $this->entries);
        self::assertNull($tracker->findPending(''));
    }

    private function registry(): Registry
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(fn (string $namespace, string $key, mixed $default = null): mixed => $this->entries[$namespace . '/' . $key] ?? $default);
        $registry->method('set')->willReturnCallback(function (string $namespace, string $key, mixed $value): void {
            $this->entries[$namespace . '/' . $key] = $value;
        });
        $registry->method('remove')->willReturnCallback(function (string $namespace, string $key): void {
            unset($this->entries[$namespace . '/' . $key]);
        });

        return $registry;
    }
}
