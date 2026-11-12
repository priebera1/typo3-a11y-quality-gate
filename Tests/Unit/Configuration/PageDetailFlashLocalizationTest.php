<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Product acceptance: Page Detail ignore feedback was hard-coded English ("1 issues ignored for rule rte.img_alt_missing
 * on this page."), so German editors read English with a wrong plural and a rule ID. Every flash message of the
 * controller now comes from a label.
 */
final class PageDetailFlashLocalizationTest extends TestCase
{
    #[Test]
    public function pageDetailFlashMessagesAreNeverLiteralEnglish(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Classes/Controller/PageDetailController.php');
        self::assertDoesNotMatchRegularExpression('/addFlashMessage\(\s*[\'"]/', $source);
        self::assertDoesNotMatchRegularExpression('/addFlashMessage\(\s*\$ignored\s*\./', $source);
        self::assertDoesNotMatchRegularExpression("/ContextualFeedbackSeverity::[A-Z]+,\\s*'Accessibility'/", $source, 'the title comes from a label');
        self::assertDoesNotMatchRegularExpression("/'error' => '[^']/", $source, 'expiry errors come from labels');
    }
}
