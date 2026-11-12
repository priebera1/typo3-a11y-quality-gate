<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RemoteScanHistoryService;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;

/**
 * Atlas D9: the history of one URL holds preview scans (page not public) and public scans. Each history offers a
 * comparison only with the previous scan of its own kind; the AQG service's history would pair them across.
 */
final class RemoteScanHistoryPagePreviewTest extends TestCase
{
    #[Test]
    public function previewAndPublicScansArePairedOnlyWithinTheirOwnKind(): void
    {
        $items = [
            $this->item('public-new', 'preview-new'),
            $this->item('preview-new', 'preview-old'),
            $this->item('preview-old', 'public-old'),
            $this->item('public-old', ''),
        ];

        $separated = $this->service()->separatePagePreviewHistory($items, ['preview-new' => true, 'preview-old' => true]);

        self::assertSame(['public-new', 'preview-new', 'preview-old', 'public-old'], array_column($separated, 'jobId'), 'Order is kept.');
        self::assertSame([false, true, true, false], array_column($separated, 'isPagePreview'));
        self::assertSame(['public-old', 'preview-old', '', ''], array_column($separated, 'compareFromJobId'));
        self::assertSame([true, true, false, false], array_column($separated, 'hasComparablePrevious'));
    }

    #[Test]
    public function aHistoryWithoutStoredPreviewScansStaysAsItWas(): void
    {
        $separated = $this->service()->separatePagePreviewHistory(
            [$this->item('b', 'a'), $this->item('a', '')],
            []
        );

        self::assertSame([false, false], array_column($separated, 'isPagePreview'));
        self::assertSame(['a', ''], array_column($separated, 'compareFromJobId'));
    }

    #[Test]
    public function legacyScansArePublicAndNeverPairWithAPreviewScan(): void
    {
        $public = ['site_identifier' => 'main', 'status' => 'completed', 'scan_scope' => 'page', 'source_type' => 'single_page', 'language_uid' => 0, 'start_url' => 'https://example.org/draft'];

        self::assertFalse(RemoteScanPairingService::isPagePreview($public), 'A row without the flag is public.');
        self::assertTrue(RemoteScanPairingService::isPagePreview($public + ['is_page_preview' => 1]));
    }

    /** @return array<string, mixed> */
    private function item(string $jobId, string $compareFromJobId): array
    {
        return [
            'jobId' => $jobId,
            'isPageScan' => true,
            'normalizedStartUrl' => 'https://example.org/draft',
            'compareFromJobId' => $compareFromJobId,
            'hasComparablePrevious' => $compareFromJobId !== '',
        ];
    }

    private function service(): RemoteScanHistoryService
    {
        return new RemoteScanHistoryService(
            $this->createMock(ExtensionContextService::class),
            $this->createMock(ProCrawlerService::class),
        );
    }
}
