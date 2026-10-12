<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\RemoteScanResponseService;

/**
 * NOT-02 (2026-10 Sol audit): storing a finished frontend scan replaced its expected page count with the loaded pages
 * (`pagesTotal = pagesScanned`), so a run that knew 3 pages and loaded 1 was stored as complete.
 */
final class RemoteScanResponseCoverageTest extends TestCase
{
    #[Test]
    public function theCrawlersExpectedPageCountIsKept(): void
    {
        $payload = (new RemoteScanResponseService())->buildResultsPayload($this->summary(1, 0, 3), $this->results());

        self::assertSame(3, $payload['pagesTotal']);
        self::assertSame(1, $payload['pagesScanned']);
    }

    #[Test]
    public function anOlderServiceFallsBackToTheTotalTheStatusPollsStored(): void
    {
        $payload = (new RemoteScanResponseService())->buildResultsPayload($this->summary(1, 0, null), $this->results(), ['pages_total' => 3, 'page_uid' => 0, 'language_uid' => 0]);

        self::assertSame(3, $payload['pagesTotal']);
    }

    #[Test]
    public function failedPagesAlwaysCountAsExpected(): void
    {
        $payload = (new RemoteScanResponseService())->buildResultsPayload($this->summary(2, 1, null), $this->results());

        self::assertSame(3, $payload['pagesTotal']);
    }

    private function summary(int $scanned, int $failed, ?int $total): object
    {
        $summary = new \stdClass();
        $summary->status = 'completed';
        $summary->pagesScanned = $scanned;
        $summary->pagesFailed = $failed;
        $summary->issuesTotal = 2;
        $summary->issuesNew = 0;
        $summary->issuesResolved = 0;
        $summary->startedAt = null;
        $summary->finishedAt = null;
        if ($total !== null) {
            $summary->pagesTotal = $total;
        }

        return $summary;
    }

    private function results(): object
    {
        $results = new \stdClass();
        $results->pages = [];

        return $results;
    }
}
