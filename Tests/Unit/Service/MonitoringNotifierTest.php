<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\MonitoringNotifier;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

final class MonitoringNotifierTest extends TestCase
{
    #[Test]
    public function aRegressionMailNamesSiteTimeChangesAndLink(): void
    {
        [$subject, $text] = $this->notifier()->compose($this->report('regression', [
            'new' => [['url' => 'https://example.org/a', 'ruleId' => 'color-contrast', 'impact' => 'serious', 'before' => 0, 'after' => 2]],
            'regressed' => [['url' => 'https://example.org/b', 'ruleId' => 'image-alt', 'impact' => 'critical', 'before' => 1, 'after' => 4]],
            'fixed' => [],
            'unverified' => [['url' => 'https://example.org/c', 'reason' => 'page_failed']],
        ], [['url' => 'https://example.org/c', 'reason' => 'page_failed']]));

        self::assertSame('[AQG] Accessibility regression: main', $subject);
        self::assertStringContainsString('Site: main (https://example.org)', $text);
        self::assertStringContainsString('Scan finished:', $text);
        self::assertStringContainsString('1 new and 1 worse issue types', $text);
        self::assertStringContainsString('[critical] image-alt on https://example.org/b (1 → 4 occurrences)', $text);
        self::assertStringContainsString('Some pages were not checked, so this list may be incomplete', $text);
        self::assertStringContainsString('https://example.org/c (page failed to load)', $text);
        self::assertStringContainsString('Investigate in AQG: https://cms.example.org/typo3/module/web/a11y?id=1', $text);
        self::assertStringContainsString('do not confirm WCAG conformance', $text);
    }

    #[Test]
    public function aFailedScanIsNeverWordedAsARegression(): void
    {
        [$subject, $text] = $this->notifier()->compose($this->report('failed', null));

        self::assertSame('[AQG] Monitoring scan failed: main', $subject);
        self::assertStringContainsString('This is not a regression', $text);
        self::assertStringNotContainsString('worse issue types', $text);
    }

    #[Test]
    public function anIncompleteScanIsNeverAnAllClearAndNamesWhatWasNotChecked(): void
    {
        [$subject, $text] = $this->notifier()->compose($this->report('incomplete', [
            'new' => [], 'regressed' => [], 'fixed' => [],
            'unverified' => [['url' => 'https://example.org/c', 'reason' => 'not_in_current']],
        ], [
            ['url' => 'https://example.org/b', 'reason' => 'evidence_incomplete'],
            ['url' => 'https://example.org/c', 'reason' => 'not_in_current'],
        ]));

        self::assertSame('[AQG] Monitoring scan incomplete: main', $subject);
        self::assertStringContainsString('cannot say whether issues are new or worse. This is not an all-clear.', $text);
        self::assertStringNotContainsString('No new or worse issues', $text);
        self::assertStringContainsString('https://example.org/b (results stored incompletely)', $text);
        self::assertStringContainsString('https://example.org/c (not scanned this time)', $text);
        self::assertStringContainsString('The baseline stays the last complete scan', $text);
    }

    #[Test]
    public function aScanWithoutCheckedPagesSaysSo(): void
    {
        [, $text] = $this->notifier()->compose($this->report('incomplete', null, [['url' => '', 'reason' => 'no_pages']]));

        self::assertStringContainsString('- the scan returned no checked pages', $text);
    }

    /**
     * @param array<string, mixed>|null $comparison
     * @param list<array{url:string,reason:string}> $gaps
     * @return array<string, mixed>
     */
    private function report(string $outcome, ?array $comparison, array $gaps = []): array
    {
        return [
            'outcome' => $outcome,
            'site' => new Site('main', 1, ['base' => 'https://example.org/']),
            'scan' => ['finished_at' => 1790000000],
            'baseline' => $comparison === null ? null : ['finished_at' => 1789000000],
            'comparison' => $comparison,
            'gaps' => $gaps,
            'backendBaseUrl' => 'https://cms.example.org',
            'languageUid' => 0,
        ];
    }

    private function notifier(): MonitoringNotifier
    {
        $uris = $this->createMock(UriBuilder::class);
        $uris->method('buildUriFromRoute')->willReturn(new Uri('/typo3/module/web/a11y?id=1&site=main&language=0'));

        return new MonitoringNotifier($this->createMock(MailerInterface::class), $uris);
    }
}
