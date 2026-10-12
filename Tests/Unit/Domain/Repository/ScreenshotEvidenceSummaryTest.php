<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;

/**
 * Audit C15: `/crawl/results` adds `pages[].screenshotEvidence` for captures with numbered outlines and an untouched
 * Original. Only the documented scalar summary is stored; a crawler without evidence stores nothing.
 */
final class ScreenshotEvidenceSummaryTest extends TestCase
{
    #[Test]
    public function onlyTheDocumentedSummaryIsKept(): void
    {
        $summary = RemoteScanRepository::screenshotEvidenceSummary([
            'version' => 1,
            'originalAvailable' => true,
            'annotationsAvailable' => true,
            'imageWidth' => 1280,
            'imageHeight' => 1338,
            'findingsTotal' => 39,
            'findingsDrawn' => 37,
            'regionsDrawn' => 24,
            'issueTypesTotal' => 12,
            'recommendedPrimaryVariant' => 'highlighted',
            'annotations' => ['regions' => ['<script>']],
        ]);

        self::assertSame(1, $summary['version']);
        self::assertTrue($summary['originalAvailable']);
        self::assertSame(12, $summary['issueTypesTotal']);
        self::assertArrayNotHasKey('annotations', $summary);
    }

    #[Test]
    public function aCrawlerWithoutEvidenceStoresNothing(): void
    {
        self::assertSame([], RemoteScanRepository::screenshotEvidenceSummary(null));
        self::assertSame([], RemoteScanRepository::screenshotEvidenceSummary(['originalAvailable' => true]));
        self::assertSame('highlighted', RemoteScanRepository::screenshotEvidenceSummary(['version' => 1, 'recommendedPrimaryVariant' => 'evil'])['recommendedPrimaryVariant']);
    }
}
