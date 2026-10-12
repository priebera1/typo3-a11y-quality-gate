<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scan\ScanResult;

/**
 * Audit C13: a site scan whose rendered checks failed on four pages looked like a complete scan. The scan now records
 * how many pages had their rendered HTML checked and which pages were skipped, with the reason, so the Overview can
 * say "Rendered HTML not checked on 4 of 25 pages" and list them.
 */
final class ScanResultRenderedCoverageTest extends TestCase
{
    #[Test]
    public function checkedAndSkippedPagesAreCountedOncePerPage(): void
    {
        $result = new ScanResult(7);
        $result->recordRenderedCheck(729, true);
        $result->recordRenderedCheck(744, false, 404, 'Rendered page returned HTTP 404.', 'hidden');
        $result->recordRenderedCheck(743, false, 500, 'Rendered page returned HTTP 500.');
        $result->recordRenderedCheck(744, false, 404, 'Rendered page returned HTTP 404.', 'hidden');

        self::assertSame(1, $result->renderedChecked);
        self::assertSame([
            ['pageUid' => 744, 'statusCode' => 404, 'message' => 'Rendered page returned HTTP 404.', 'reason' => 'hidden'],
            ['pageUid' => 743, 'statusCode' => 500, 'message' => 'Rendered page returned HTTP 500.', 'reason' => ''],
        ], $result->renderedSkipped);
    }

    /**
     * Hidden pages are not shown to the visitor the rendered check requests them as: not checkable by design, unlike
     * a public page whose fetch failed. The notification and the Overview tell the two apart.
     */
    #[Test]
    public function pagesVisitorsAreNotShownAreCountedApart(): void
    {
        $result = new ScanResult(7);
        $result->recordRenderedCheck(732, false, 404, 'Rendered page returned HTTP 404.', 'hidden');
        $result->recordRenderedCheck(734, false, 404, 'Rendered page returned HTTP 404.', 'scheduled');
        $result->recordRenderedCheck(740, false, 0, 'Rendered page fetch timed out.');

        $coverage = $result->renderedCoverage();

        self::assertSame(3, $coverage['skipped']);
        self::assertSame(2, $coverage['notPublic']);
    }

    #[Test]
    public function theStoredListIsBoundedButTheCountIsNot(): void
    {
        $result = new ScanResult(7);
        for ($page = 1; $page <= 120; $page++) {
            $result->recordRenderedCheck($page, false, 0, 'Rendered page fetch timed out.');
        }

        $coverage = $result->renderedCoverage();

        self::assertSame(120, $coverage['skipped']);
        self::assertCount(ScanResult::RENDERED_SKIPPED_LIST_LIMIT, $coverage['skippedPages']);
        self::assertSame(0, $coverage['checked']);
    }
}
