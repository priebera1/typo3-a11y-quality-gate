<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\RemoteScanFindingIndex;
use Priebera\A11yQualityGate\Service\ScanComparisonService;

/**
 * Only URLs both scans covered completely are compared; everything else is unverified, never fixed.
 */
final class ScanComparisonServiceTest extends TestCase
{
    #[Test]
    public function findingsAreSortedIntoFixedNewWorseAndUnresolved(): void
    {
        $comparison = $this->service()->compareIndexes(
            ['a' => $this->page('https://x/a', ['contrast' => [3, 'serious'], 'alt' => [2, 'critical'], 'label' => [1, 'critical']])],
            ['a' => $this->page('https://x/a', ['contrast' => [5, 'serious'], 'alt' => [2, 'critical'], 'heading' => [1, 'moderate']])],
        );

        self::assertSame(['label'], array_column($comparison['fixed'], 'ruleId'));
        self::assertSame(['heading'], array_column($comparison['new'], 'ruleId'));
        self::assertSame(['contrast'], array_column($comparison['regressed'], 'ruleId'));
        self::assertSame(['alt'], array_column($comparison['unresolved'], 'ruleId'));
        self::assertSame(1, $comparison['comparedPages']);
    }

    #[Test]
    public function aFailedOrIncompleteOrUncoveredPageIsUnverifiedNotFixed(): void
    {
        $comparison = $this->service()->compareIndexes(
            [
                'failed' => $this->page('https://x/failed', ['alt' => [1, 'critical']]),
                'incomplete' => $this->page('https://x/incomplete', ['alt' => [1, 'critical']]),
                'gone' => $this->page('https://x/gone', ['alt' => [1, 'critical']]),
            ],
            [
                'failed' => $this->page('https://x/failed', [], true),
                'incomplete' => $this->page('https://x/incomplete', [], false, false),
                'added' => $this->page('https://x/added', ['alt' => [1, 'critical']]),
            ],
        );

        self::assertSame([], $comparison['fixed']);
        self::assertSame([], $comparison['new']);
        self::assertSame(
            ['https://x/added' => 'not_in_baseline', 'https://x/failed' => 'page_failed', 'https://x/gone' => 'not_in_current', 'https://x/incomplete' => 'evidence_incomplete'],
            array_column($comparison['unverified'], 'reason', 'url')
        );
    }

    #[Test]
    public function theRegressionFingerprintIgnoresOrderAndFixesButNotOccurrences(): void
    {
        $service = $this->service();
        $a = ['new' => [['url' => 'u1', 'ruleId' => 'r1', 'before' => 0, 'after' => 2], ['url' => 'u2', 'ruleId' => 'r2', 'before' => 0, 'after' => 1]], 'regressed' => [], 'fixed' => [['url' => 'u3', 'ruleId' => 'r3', 'before' => 1, 'after' => 0]]];
        $b = ['new' => [['url' => 'u2', 'ruleId' => 'r2', 'before' => 0, 'after' => 1], ['url' => 'u1', 'ruleId' => 'r1', 'before' => 0, 'after' => 2]], 'regressed' => [], 'fixed' => []];
        $worse = ['new' => [['url' => 'u2', 'ruleId' => 'r2', 'before' => 0, 'after' => 1], ['url' => 'u1', 'ruleId' => 'r1', 'before' => 0, 'after' => 5]], 'regressed' => [], 'fixed' => []];

        self::assertSame($service->regressionFingerprint($a), $service->regressionFingerprint($b));
        self::assertNotSame($service->regressionFingerprint($b), $service->regressionFingerprint($worse), 'more occurrences are a new state');
        self::assertSame('', $service->regressionFingerprint(['new' => [], 'regressed' => []]));
    }

    /**
     * @param array<string, array{0:int,1:string}> $rules
     * @return array<string, mixed>
     */
    private function page(string $url, array $rules, bool $failed = false, bool $complete = true): array
    {
        $normalized = [];
        foreach ($rules as $ruleId => [$occurrences, $impact]) {
            $normalized[$ruleId] = ['occurrences' => $occurrences, 'impact' => $impact];
        }

        return ['url' => $url, 'remotePageUid' => 1, 'failed' => $failed, 'httpStatus' => $failed ? 500 : 200, 'evidenceComplete' => $complete && !$failed, 'rules' => $normalized];
    }

    private function service(): ScanComparisonService
    {
        return new ScanComparisonService($this->createMock(RemoteScanFindingIndex::class));
    }
}
