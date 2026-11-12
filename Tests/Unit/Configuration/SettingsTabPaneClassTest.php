<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Settings renders only the active tab (Templates/Settings/Index.html, f:switch on activeTab). The tab panes carried an
 * inline condition with doubled quotes (`{f:if(condition: '{activeTab} == ''rules''', …)}`) that Fluid left unparsed, so
 * the pane's class attribute shipped the raw expression. Product acceptance found it on TYPO3 13 and 14.
 */
final class SettingsTabPaneClassTest extends TestCase
{
    #[Test]
    public function everyTabPaneHasAPlainActiveClass(): void
    {
        $partials = glob(dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/Tab*.html') ?: [];
        self::assertNotSame([], $partials);
        foreach ($partials as $partial) {
            $source = (string)file_get_contents($partial);
            // A doubled quote is no escape in Fluid's inline syntax (only \\' is): the whole expression stays text.
            self::assertDoesNotMatchRegularExpression("/(?<![\\\\'])''[a-z_]/", $source, basename($partial) . ' uses a doubled-quote inline expression');
            if (str_contains($source, 'tab-pane')) {
                self::assertMatchesRegularExpression('/class="tab-pane fade show active"/', $source, basename($partial));
            }
        }
    }
}
