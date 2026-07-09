<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiIframeTitleSuggestionTemplateTest extends TestCase
{
    #[Test]
    public function localIssueCardHasReviewOnlyAiIframeTitlePanel(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Issue/AiIframeTitleSuggestion.html') ?: '';

        self::assertStringContainsString('data-aqg-ai-iframe-title-card="true"', $partial);
        self::assertStringContainsString('data-action="aqg-suggest-iframe-title"', $partial);
        self::assertStringContainsString('data-aqg-ai-iframe-title-suggestion="true"', $partial);
        self::assertStringContainsString('data-aqg-ai-iframe-title-no-suggestion="true"', $partial);
        self::assertStringContainsString('data-aqg-ai-iframe-title-copy="true"', $partial);
        self::assertStringContainsString('data-action="aqg-copy-iframe-title"', $partial);
        self::assertStringNotContainsString('data-action="aqg-apply-iframe-title"', $partial);
    }

    #[Test]
    public function pageDetailLoadsIframeTitleSuggestionEndpointAndModule(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/PageDetail/Show.html') ?: '';
        $controller = file_get_contents(__DIR__ . '/../../../Classes/Controller/PageDetailController.php') ?: '';

        self::assertStringContainsString('data-aqg-ai-iframe-title-url="{aiSuggestIframeTitleUrl}"', $template);
        self::assertStringContainsString('data-message-iframe-title-no-suggestion', $template);
        self::assertStringContainsString('@priebera/a11y-quality-gate/ai-iframe-title-suggestion.js', $controller);
        self::assertStringContainsString('ajax_a11y_ai_suggest_iframe_title', $controller);
    }

    #[Test]
    public function ajaxRouteIsRegisteredForReadOnlySuggestion(): void
    {
        $routes = require __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';

        self::assertSame(
            '/a11y/ai/iframe-title-suggestion',
            $routes['a11y_ai_suggest_iframe_title']['path'] ?? '',
        );
        self::assertSame(['POST'], $routes['a11y_ai_suggest_iframe_title']['methods'] ?? []);
    }
}
