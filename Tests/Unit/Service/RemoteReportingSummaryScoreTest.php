<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\RemoteReportingSummaryService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

/**
 * The crawler marks a run that loaded no page with `available: false` and leaves `value` out
 * (aqg-crawler summary-score-coverage.test.ts). The extension must never turn that into a 0/100 or keep a stale value.
 */
final class RemoteReportingSummaryScoreTest extends TestCase
{
    #[Test]
    public function anUnavailableScoreHasNoValue(): void
    {
        $score = $this->score(['available' => false, 'max' => 100, 'tone' => 'warning', 'label' => 'No page could be scanned']);

        self::assertFalse($score['hasValue']);
    }

    #[Test]
    public function anUnavailableScoreHasNoValueEvenIfAValueIsSent(): void
    {
        $score = $this->score(['available' => false, 'value' => 98, 'max' => 100, 'tone' => 'success']);

        self::assertFalse($score['hasValue']);
    }

    #[Test]
    public function anAvailableScoreKeepsItsValue(): void
    {
        $score = $this->score(['available' => true, 'value' => 98, 'max' => 100, 'tone' => 'success']);

        self::assertTrue($score['hasValue']);
        self::assertSame(98, $score['value']);
        self::assertSame('good', $score['tone']);
    }

    #[Test]
    public function aScoreFromAnOlderApiWithoutTheFlagKeepsItsValue(): void
    {
        $score = $this->score(['value' => 5, 'max' => 100, 'tone' => 'danger']);

        self::assertTrue($score['hasValue']);
        self::assertSame(5, $score['value']);
    }

    /**
     * @param array<string, mixed> $apiScore
     * @return array<string, mixed>
     */
    private function score(array $apiScore): array
    {
        $languages = new BackendLanguageService();
        $service = new RemoteReportingSummaryService(new RuleMetadataPresentationService($languages), $languages);

        $result = $service->buildFromApiSummaryWithFallback([], [], [], [], [], [], $apiScore);

        return $result['score'];
    }
}
