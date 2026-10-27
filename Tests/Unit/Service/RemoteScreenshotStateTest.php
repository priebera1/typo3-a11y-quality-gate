<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\RemoteScreenshotState;

/**
 * A stored frontend page shows its screenshot, or a precise reason: deleted after the retention period, deleted,
 * not captured, never taken — and a load failure of a screenshot that should exist is never called an expiry.
 */
final class RemoteScreenshotStateTest extends TestCase
{
    private const NOW = 1_800_000_000;

    #[Test]
    public function anAvailableScreenshotPastItsReportedEndIsExpiredWithoutAskingTheService(): void
    {
        $page = ['screenshot_path' => 'storage/screenshots/a.png', 'screenshot_state' => 'available', 'screenshot_expires_at' => self::NOW - 1];
        self::assertSame('expired', RemoteScreenshotState::resolve($page, self::NOW));
        $page['screenshot_expires_at'] = self::NOW + 86400;
        self::assertSame('available', RemoteScreenshotState::resolve($page, self::NOW));
        $page['screenshot_expires_at'] = 0;
        self::assertSame('available', RemoteScreenshotState::resolve($page, self::NOW), 'an older crawler reports no end');
    }

    #[Test]
    public function aPageWithoutScreenshotNamesItsReason(): void
    {
        self::assertSame('expired', RemoteScreenshotState::resolve(['screenshot_path' => '', 'screenshot_state' => 'expired'], self::NOW));
        self::assertSame('deleted', RemoteScreenshotState::resolve(['screenshot_path' => '', 'screenshot_state' => 'deleted'], self::NOW));
        self::assertSame('failed', RemoteScreenshotState::resolve(['screenshot_path' => '', 'screenshot_state' => 'failed'], self::NOW));
        self::assertSame('not_captured', RemoteScreenshotState::resolve(['screenshot_path' => '', 'screenshot_state' => 'not_captured'], self::NOW));
    }

    /** A page stored before the crawler reported states, or with an unknown value, has no known reason. */
    #[Test]
    public function aLegacyOrUnrecognisedPageWithoutScreenshotIsUnknown(): void
    {
        self::assertSame('unknown', RemoteScreenshotState::resolve(['screenshot_path' => '', 'screenshot_state' => ''], self::NOW));
        self::assertSame('unknown', RemoteScreenshotState::resolve(['screenshot_path' => '', 'screenshot_state' => 'gone'], self::NOW));
        self::assertSame('unknown', RemoteScreenshotState::resolve(['screenshot_path' => '', 'screenshot_state' => 'available'], self::NOW));
        self::assertSame('unknown', RemoteScreenshotState::resolve([], self::NOW));
    }

    #[Test]
    public function onlyTheServicesExplicitCodesMeanExpiredOrDeleted(): void
    {
        self::assertSame('expired', RemoteScreenshotState::fromServiceError(410, 'screenshot_expired'));
        self::assertSame('deleted', RemoteScreenshotState::fromServiceError(410, 'screenshot_deleted'));
        self::assertSame('failed', RemoteScreenshotState::fromServiceError(404, 'screenshot_failed'));
        self::assertSame('not_captured', RemoteScreenshotState::fromServiceError(404, 'screenshot_missing'));
        foreach ([[404, 'screenshot_file_missing'], [503, 'screenshot_storage_unavailable'], [403, 'forbidden_resource'], [410, ''], [404, 'screenshot_expired'], [500, '']] as [$status, $code]) {
            self::assertSame('unavailable', RemoteScreenshotState::fromServiceError($status, $code), $status . ' ' . $code);
        }
    }

    #[Test]
    public function reportedValuesAreNormalised(): void
    {
        self::assertSame('expired', RemoteScreenshotState::normalizeReported(' Expired '));
        self::assertSame('', RemoteScreenshotState::normalizeReported('gone'));
        self::assertSame('', RemoteScreenshotState::normalizeReported(null));
        self::assertSame(0, RemoteScreenshotState::timestampOf(null));
        self::assertSame(0, RemoteScreenshotState::timestampOf('not a date'));
        self::assertSame(1_767_225_600, RemoteScreenshotState::timestampOf('2026-01-01T00:00:00.000Z'));
    }
}
