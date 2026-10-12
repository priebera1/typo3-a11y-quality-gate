<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\ViewModel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteScanSignal;

/**
 * The Overview's frontend signal is decided by coverage before score or tone.
 *
 * Regression guard: a completed page scan whose only page answered HTTP 404 (pages_scanned 0, pages_failed 1)
 * rendered "Good automated signal · AQG signal 98 / 100", because the API scored it 100 - 2 (failed-page penalty)
 * and the extension took that tone without looking at how many pages were actually checked.
 */
final class RemoteScanSignalTest extends TestCase
{
    private const GOOD_API_SCORE = ['value' => 98, 'max' => 100, 'tone' => 'good', 'hasValue' => true];

    #[Test]
    public function aScanWithoutAnyLoadedPageIsNotGoodEvenWithAGoodApiScore(): void
    {
        $signal = RemoteScanSignal::resolve($this->scan(0, 1, 0), self::GOOD_API_SCORE, false, []);

        self::assertSame('no-coverage', $signal->tone);
        self::assertFalse($signal->showScoreValue, 'A score computed from zero checked pages must not be shown.');
    }

    #[Test]
    public function aScanWithoutAnyLoadedPageIsNotGoodWithoutAnApiScore(): void
    {
        $signal = RemoteScanSignal::resolve($this->scan(0, 1, 0), [], false, []);

        self::assertSame('no-coverage', $signal->tone);
    }

    #[Test]
    public function aScanThatReportsNoPagesAtAllIsNotGood(): void
    {
        $signal = RemoteScanSignal::resolve($this->scan(0, 0, 0), self::GOOD_API_SCORE, false, []);

        self::assertSame('no-coverage', $signal->tone);
    }

    #[Test]
    public function failedPagesMakeAnOtherwiseGoodScanIncomplete(): void
    {
        $signal = RemoteScanSignal::resolve($this->scan(17, 2, 19), ['value' => 92, 'max' => 100, 'tone' => 'good', 'hasValue' => true], false, []);

        self::assertSame('incomplete', $signal->tone);
        self::assertTrue($signal->showScoreValue, 'The checked pages still have a legitimate score.');
        self::assertSame(2, $signal->coverage?->failedPages);
        self::assertSame(19, $signal->coverage?->expectedPages);
    }

    #[Test]
    public function pagesThatWereNeverReachedMakeTheScanIncomplete(): void
    {
        $signal = RemoteScanSignal::resolve($this->scan(10, 0, 19), self::GOOD_API_SCORE, false, []);

        self::assertSame('incomplete', $signal->tone);
    }

    #[Test]
    public function aFailedPageRowCountsEvenWhenTheRunCounterSaysNothingFailed(): void
    {
        $signal = RemoteScanSignal::resolve($this->scan(1, 0, 1), self::GOOD_API_SCORE, false, [], 1);

        self::assertSame('incomplete', $signal->tone);
    }

    #[Test]
    public function aCompleteScanKeepsTheApiTone(): void
    {
        self::assertSame('good', RemoteScanSignal::resolve($this->scan(19, 0, 19), self::GOOD_API_SCORE, false, [])->tone);
        self::assertSame(
            'critical',
            RemoteScanSignal::resolve($this->scan(19, 0, 19), ['value' => 5, 'max' => 100, 'tone' => 'critical', 'hasValue' => true], true, [])->tone
        );
    }

    #[Test]
    public function aCompleteScanWithoutScoreFallsBackToImpactAndPriorityFixes(): void
    {
        self::assertSame('critical', RemoteScanSignal::resolve($this->scan(3, 0, 3), [], true, ['overallImpact' => 'critical'])->tone);
        self::assertSame('attention', RemoteScanSignal::resolve($this->scan(3, 0, 3), [], true, [])->tone);
        self::assertSame('good', RemoteScanSignal::resolve($this->scan(3, 0, 3), [], false, [])->tone);
    }

    #[Test]
    public function noScanIsNoScan(): void
    {
        $signal = RemoteScanSignal::resolve(null, self::GOOD_API_SCORE, false, []);

        self::assertSame('no-scan', $signal->tone);
        self::assertNull($signal->coverage);
    }

    /**
     * @return array<string, int|string>
     */
    private function scan(int $pagesScanned, int $pagesFailed, int $pagesTotal): array
    {
        return [
            'uid' => 79,
            'status' => 'completed',
            'pages_scanned' => $pagesScanned,
            'pages_failed' => $pagesFailed,
            'pages_total' => $pagesTotal,
        ];
    }
}
