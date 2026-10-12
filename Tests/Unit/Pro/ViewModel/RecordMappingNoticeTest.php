<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\ViewModel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\ViewModel\RecordMappingNotice;

/**
 * Why frontend findings of a page are not linked to TYPO3 records.
 *
 * Regression guard: a site without a Scanner Token renders no AQG record markers for the crawler, yet every unmapped
 * finding only said "No mapped TYPO3 record · could not locate source content", with no hint at the cause or the fix.
 */
final class RecordMappingNoticeTest extends TestCase
{
    #[Test]
    public function aSiteWithoutScannerTokenExplainsItsUnmappedFindings(): void
    {
        $notice = RecordMappingNotice::build($this->issues(mapped: 1, unmapped: 2), siteHasScannerToken: false, isFreePreview: false, settingsUrl: '/typo3/settings');

        self::assertTrue($notice['show']);
        self::assertTrue($notice['scannerTokenMissing']);
        self::assertSame(2, $notice['unmappedNodes']);
        self::assertSame(3, $notice['totalNodes']);
        // Only some occurrences are unlinked: the headline must not say that none is linked.
        self::assertFalse($notice['allUnmapped']);
        self::assertSame('/typo3/settings', $notice['settingsUrl']);
    }

    #[Test]
    public function aPageWithoutAnyLinkedOccurrenceSaysSo(): void
    {
        $notice = RecordMappingNotice::build($this->issues(mapped: 0, unmapped: 3), false, false, '');

        self::assertTrue($notice['show']);
        self::assertTrue($notice['allUnmapped']);
        self::assertSame(3, $notice['totalNodes']);
    }

    #[Test]
    public function aSiteWithScannerTokenNeedsNoNotice(): void
    {
        $notice = RecordMappingNotice::build($this->issues(mapped: 1, unmapped: 2), siteHasScannerToken: true, isFreePreview: false, settingsUrl: '/typo3/settings');

        self::assertFalse($notice['show']);
        self::assertFalse($notice['scannerTokenMissing']);
    }

    #[Test]
    public function aPageWhoseFindingsAreAllMappedNeedsNoNotice(): void
    {
        self::assertFalse(RecordMappingNotice::build($this->issues(mapped: 3, unmapped: 0), false, false, '')['show']);
    }

    #[Test]
    public function aFreePreviewNeverMapsRecordsAndGetsNoTokenNotice(): void
    {
        self::assertFalse(RecordMappingNotice::build($this->issues(mapped: 0, unmapped: 2), false, true, '/typo3/settings')['show']);
    }

    /**
     * Regression (audit C8): "14 of 62 occurrences are not linked … TYPO3 did not mark its content records" stood above
     * 48 linked rows, and counted page-level nodes (`html`, `body`) that no token can ever link.
     */
    #[Test]
    public function pageLevelNodesAreNotCountedAsFixableByAToken(): void
    {
        $issues = $this->issues(mapped: 48, unmapped: 12);
        $issues[] = ['nodes' => [
            ['hasRecordMapping' => false, 'target_json' => '["html"]', 'html_snippet' => '<html lang="en">'],
            ['hasRecordMapping' => false, 'target_json' => '["body"]', 'html_snippet' => '<body class="x">'],
        ]];

        $notice = RecordMappingNotice::build($issues, false, false, '/typo3/settings');

        self::assertTrue($notice['show']);
        self::assertSame(62, $notice['totalNodes']);
        self::assertSame(48, $notice['linkedNodes']);
        self::assertSame(2, $notice['pageLevelNodes']);
        self::assertSame(12, $notice['unmappedNodes'], 'Only content occurrences a token could link are counted.');
        self::assertFalse($notice['allUnmapped']);
    }

    #[Test]
    public function onlyPageLevelNodesUnlinkedNeedNoTokenNotice(): void
    {
        $issues = $this->issues(mapped: 3, unmapped: 0);
        $issues[] = ['nodes' => [['hasRecordMapping' => false, 'target_json' => '["html"]', 'html_snippet' => '<html>']]];

        $notice = RecordMappingNotice::build($issues, false, false, '');

        self::assertFalse($notice['show']);
        self::assertSame(1, $notice['pageLevelNodes']);
    }

    /**
     * @return list<array{nodes: list<array<string, mixed>>}>
     */
    private function issues(int $mapped, int $unmapped): array
    {
        $nodes = [];
        for ($i = 0; $i < $mapped; $i++) {
            $nodes[] = ['hasRecordMapping' => true, 'mapped_table' => 'tt_content', 'mapped_uid' => 100 + $i];
        }
        for ($i = 0; $i < $unmapped; $i++) {
            $nodes[] = ['hasRecordMapping' => false, 'mapped_table' => '', 'mapped_uid' => 0];
        }

        return [['nodes' => array_slice($nodes, 0, 1)], ['nodes' => array_slice($nodes, 1)]];
    }
}
