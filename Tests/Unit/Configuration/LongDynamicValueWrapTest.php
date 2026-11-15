<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Owner QA 3.0.0 (TYPO3 14.3.7): a 230-character image file name in Page Detail ("Image "t9Qvr….jpeg" has no alt text."
 * above the Alternative text card) widened the occurrence and the whole module page instead of wrapping. Dynamic values
 * wrap where they are (overflow-wrap: anywhere, min-width: 0 on flex/grid children), never by a global break-all or by
 * hiding the overflow; the compiled CSS is the npm run build output of these sources.
 */
final class LongDynamicValueWrapTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    #[Test]
    public function theOccurrenceLineAndContextWrapLongValues(): void
    {
        $scss = $this->read('Resources/Private/Scss/components/_scan-views.scss');
        foreach (['aqg-occ__what', 'aqg-occ__context'] as $class) {
            self::assertMatchesRegularExpression('/\.aqg-module\.aqg-sv \.' . $class . ' \{[^}]*min-width: 0;[^}]*overflow-wrap: anywhere;/s', $scss, $class);
        }
        $css = $this->read('Resources/Public/Css/backend.css');
        self::assertMatchesRegularExpression('/\.aqg-module\.aqg-sv \.aqg-occ__what\{[^}]*min-width:0[^}]*overflow-wrap:anywhere/', $css);
    }

    #[Test]
    public function theAlternativeTextCardWrapsTheFileNameWithoutBreakAll(): void
    {
        $scss = $this->read('Resources/Private/Scss/views/_page-detail.scss');
        foreach (['aqg-rem__filename', 'aqg-rem__file-path'] as $class) {
            self::assertMatchesRegularExpression('/\.a11y-page-detail \.' . $class . ' \{[^}]*overflow-wrap: anywhere;/s', $scss, $class);
            self::assertDoesNotMatchRegularExpression('/\.a11y-page-detail \.' . $class . ' \{[^}]*word-break: break-all/s', $scss, $class);
        }
        self::assertStringContainsString('.a11y-page-detail .aqg-rem__head-body { flex: 1 1 auto; min-width: 0;', $scss);
        $template = $this->read('Resources/Private/Partials/Issue/ImageRemediationActions.html');
        self::assertStringContainsString('<span class="aqg-rem__file-path">{issue.imageFileName}</span>', $template);
    }

    #[Test]
    public function aiResultAndReviewPanelsWrapDynamicText(): void
    {
        $scss = $this->read('Resources/Private/Scss/remediation.scss');
        self::assertMatchesRegularExpression('/\.aqg-ai-result__text,[^{]*\.aqg-remediation-review__before[^{]*\{\s*overflow-wrap: anywhere;/s', $scss);
        $css = $this->read('Resources/Public/Css/remediation.css');
        self::assertMatchesRegularExpression('/\.aqg-ai-result__text,[^{]*\{overflow-wrap:anywhere\}/', $css);
        self::assertStringNotContainsString('overflow-x:hidden', (string)preg_replace('/\s+/', '', $scss));
    }

    private function read(string $path): string
    {
        return file_get_contents(self::ROOT . $path) ?: '';
    }
}
