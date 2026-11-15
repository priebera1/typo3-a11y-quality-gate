<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RemoteScanHistoryService;

/**
 * D-1 (AQG 3.0 production acceptance, 2026-11-13): a sitemap scan with a 4-page budget and its own priorities and
 * exclusions became the baseline of a 19-page scan of the same site. The AQG service counts new findings over both
 * scans whatever its compatibility verdict, so the comparison read "297 new" with a regression status. The extension
 * now shows the service's "not directly comparable" verdict instead of numbers, and keeps genuine regressions.
 */
final class RemoteScanHistoryScopeAwareCompareTest extends TestCase
{
    private const FROM = '7cdac2cf-0000-4000-8000-000000000001';
    private const TO = '300a4dea-0000-4000-8000-000000000002';

    #[Test]
    public function theProductionFourVersusNineteenPageCaseShowsNoCountsAndNoRegression(): void
    {
        $comparison = $this->compare($this->productionPayload([
            'sameSite' => true, 'sameSourceType' => true, 'sameStartUrl' => true, 'sameLanguage' => true,
            'sameScope' => false, 'samePageBudget' => false, 'directlyComparable' => false, 'reason' => 'different_scope',
        ]));

        self::assertTrue($comparison['available']);
        self::assertFalse($comparison['comparable']);
        self::assertSame('not_comparable', $comparison['status']);
        self::assertSame('neutral', $comparison['statusTone']);
        self::assertSame('Not directly comparable', $comparison['statusLabel']);
        self::assertStringContainsString('The scan scope changed', $comparison['statusMessage']);
        self::assertSame([], $comparison['summary']);
        self::assertFalse($comparison['hasByRule']);
        self::assertFalse($comparison['hasByPage']);
        self::assertFalse($comparison['hasTopChanges']);
        self::assertSame([], $comparison['acceptanceEvidence']);
        self::assertSame(self::FROM, $comparison['previousScan']['jobId']);
        self::assertSame(self::TO, $comparison['currentScan']['jobId']);
        $encoded = (string)json_encode($comparison);
        self::assertStringNotContainsString('297', $encoded);
        self::assertStringNotContainsString('Regression signal', $encoded);
    }

    #[Test]
    public function eachReasonOfTheServiceHasItsOwnExplanation(): void
    {
        $messages = [];
        foreach (['different_page_budget', 'scope_not_recorded', 'different_language', 'a_reason_added_later'] as $reason) {
            $messages[$reason] = $this->compare($this->productionPayload(['directlyComparable' => false, 'sameScope' => false, 'reason' => $reason]))['statusMessage'];
        }

        self::assertStringContainsString('The page limit changed', $messages['different_page_budget']);
        self::assertStringContainsString('before the AQG service recorded', $messages['scope_not_recorded']);
        // Reasons the extension knows no sentence for get the general one, never the counts.
        self::assertSame('These scans are not directly comparable. Open each scan to review its findings.', $messages['different_language']);
        self::assertSame($messages['different_language'], $messages['a_reason_added_later']);
    }

    #[Test]
    public function aGenuineRegressionBetweenComparableScansKeepsItsCountsAndStatus(): void
    {
        $payload = $this->productionPayload(['sameScope' => true, 'samePageBudget' => true, 'directlyComparable' => true, 'reason' => null]);
        $payload['summary'] = ['newIssues' => 12, 'resolvedIssues' => 3, 'unchangedIssues' => 70, 'improvedPages' => 1, 'worsenedPages' => 4, 'scoreDelta' => -6];

        $comparison = $this->compare($payload);

        self::assertTrue($comparison['comparable']);
        self::assertTrue($comparison['scopeVerdict']);
        self::assertSame('regression', $comparison['status']);
        self::assertSame(12, $comparison['summary']['newIssues']);
        self::assertSame(3, $comparison['summary']['resolvedIssues']);
        self::assertTrue($comparison['hasByPage']);
    }

    #[Test]
    public function anAnswerWithoutTheScopeVerdictKeepsItsCountsForTheCallersPageCheck(): void
    {
        // AQG service before crawler 032: compatibility without sameScope, or none at all.
        $legacy = $this->productionPayload(['sameSite' => true, 'sameSourceType' => true, 'sameStartUrl' => true, 'sameLanguage' => true, 'directlyComparable' => true, 'reason' => null]);
        $none = $legacy;
        unset($none['compatibility']);

        foreach ([$legacy, $none] as $payload) {
            $comparison = $this->compare($payload);
            self::assertTrue($comparison['comparable']);
            self::assertFalse($comparison['scopeVerdict']);
            self::assertSame(297, $comparison['summary']['newIssues']);
        }
    }

    #[Test]
    public function theCallersPageCheckReplacesTheCountsWithThePageDifference(): void
    {
        $service = $this->service($this->createMock(ProCrawlerService::class));
        $comparable = $this->compare($this->productionPayload(['directlyComparable' => true]));

        $guarded = $service->notComparable($comparable, 'different_pages', ['onlyInEarlier' => 0, 'onlyInLater' => 15]);

        self::assertFalse($guarded['comparable']);
        self::assertSame([], $guarded['summary']);
        self::assertSame('The scans checked different pages (0 only in the earlier scan, 15 only in the later scan). A reliable direct comparison is not available. Open each scan to review its findings.', $guarded['statusMessage']);
        self::assertSame(self::TO, $guarded['currentScan']['jobId']);
    }

    #[Test]
    public function aNotComparableRegressionAnswerIsNeitherARegressionNorAClearResult(): void
    {
        $crawler = $this->createMock(ProCrawlerService::class);
        $crawler->method('getRegressionAlert')->willReturn([
            'status' => 'not_comparable',
            'alertLevel' => 'high',
            'regressionDetected' => true,
            'previous' => ['jobId' => self::FROM],
            'current' => ['jobId' => self::TO],
            'notification' => ['shouldNotify' => true, 'title' => 'Regression detected', 'summary' => '297 new'],
            'recommendedAction' => ['type' => 'compare', 'label' => 'Review'],
        ]);

        $alert = $this->service($crawler)->loadRegressionAlert('https://a.test/', 'client-a', 'sitemap', 'https://a.test/', 0, self::TO . '@1');

        self::assertFalse($alert['regressionDetected']);
        self::assertFalse($alert['shouldNotify']);
        self::assertSame('none', $alert['alertLevel']);
        self::assertSame('neutral', $alert['tone']);
        self::assertSame('none', $alert['actionType']);
        self::assertSame('No regression signal: the previous scan covered a different scope.', $alert['title']);
        self::assertStringNotContainsString('297', (string)json_encode($alert));
    }

    #[Test]
    public function anEvaluatedRegressionAnswerIsUnchanged(): void
    {
        $crawler = $this->createMock(ProCrawlerService::class);
        $crawler->method('getRegressionAlert')->willReturn([
            'status' => 'evaluated', 'alertLevel' => 'high', 'regressionDetected' => true,
            'previous' => ['jobId' => self::FROM], 'current' => ['jobId' => self::TO],
            'recommendedAction' => ['type' => 'compare'],
        ]);

        $alert = $this->service($crawler)->loadRegressionAlert('https://a.test/', 'client-a', 'sitemap', 'https://a.test/', 0, self::TO . '@2');

        self::assertTrue($alert['regressionDetected']);
        self::assertSame('high', $alert['alertLevel']);
        self::assertSame('warning', $alert['tone']);
        self::assertSame('compare', $alert['actionType']);
    }

    /**
     * The production answer of 2026-11-13 (`/crawl/compare` 7cdac2cf → 300a4dea), with the compatibility under test.
     *
     * @param array<string, mixed> $compatibility
     * @return array<string, mixed>
     */
    private function productionPayload(array $compatibility): array
    {
        return [
            'contractVersion' => '1.0',
            'from' => ['jobId' => self::FROM, 'pagesScanned' => 4, 'issuesTotal' => 79, 'finishedAt' => '2026-11-13T11:58:07Z'],
            'to' => ['jobId' => self::TO, 'pagesScanned' => 19, 'issuesTotal' => 376, 'finishedAt' => '2026-11-13T11:59:37Z'],
            'compatibility' => $compatibility,
            'summary' => ['newIssues' => 297, 'resolvedIssues' => 0, 'unchangedIssues' => 79, 'improvedPages' => 0, 'worsenedPages' => 15, 'scoreDelta' => -9],
            'byRule' => [['ruleId' => 'color-contrast', 'fromIssuesTotal' => 20, 'toIssuesTotal' => 140, 'delta' => 120, 'status' => 'worsened']],
            'byPage' => [['url' => 'https://a.test/only-in-later', 'fromIssuesTotal' => 0, 'toIssuesTotal' => 35, 'delta' => 35, 'status' => 'new']],
            'topChanges' => [['type' => 'page', 'id' => 'https://a.test/only-in-later', 'label' => 'https://a.test/only-in-later', 'delta' => 35, 'status' => 'new', 'message' => '35 new']],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function compare(array $payload): array
    {
        $crawler = $this->createMock(ProCrawlerService::class);
        $crawler->method('compareScans')->willReturn($payload);

        return $this->service($crawler)->loadCompare('https://a.test/', self::FROM, self::TO);
    }

    private function service(ProCrawlerService $crawler): RemoteScanHistoryService
    {
        $context = $this->createMock(ExtensionContextService::class);
        $context->method('getNormalizedDomainFromSiteBase')->willReturn('a.test');
        $context->method('getExtensionVersion')->willReturn('3.0.0');

        return new RemoteScanHistoryService($context, $crawler);
    }
}
