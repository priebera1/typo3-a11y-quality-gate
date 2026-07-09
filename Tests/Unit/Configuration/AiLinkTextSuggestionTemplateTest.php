<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiLinkTextSuggestionTemplateTest extends TestCase
{
    #[Test]
    public function localIssueCardHasReviewOnlyAiLinkTextPanel(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Issue/AiLinkTextSuggestion.html') ?: '';

        self::assertStringContainsString('data-aqg-ai-link-text-card="true"', $partial);
        self::assertStringContainsString('data-action="aqg-suggest-link-text"', $partial);
        self::assertStringContainsString('data-aqg-ai-link-text-suggestion="true"', $partial);
        self::assertStringContainsString('data-aqg-ai-link-text-no-suggestion="true"', $partial);
        self::assertStringContainsString('data-aqg-ai-link-text-field-row="true"', $partial);
        self::assertStringContainsString('data-action="aqg-copy-link-text"', $partial);
        self::assertStringNotContainsString('data-action="aqg-apply-link-text"', $partial);
    }

    #[Test]
    public function pageDetailLoadsLinkTextSuggestionEndpointAndModule(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/PageDetail/Show.html') ?: '';
        $controller = file_get_contents(__DIR__ . '/../../../Classes/Controller/PageDetailController.php') ?: '';

        self::assertStringContainsString('data-aqg-ai-link-text-url="{aiSuggestLinkTextUrl}"', $template);
        self::assertStringContainsString('@priebera/a11y-quality-gate/ai-link-text-suggestion.js', $controller);
        self::assertStringContainsString('ajax_a11y_ai_suggest_link_text', $controller);
        self::assertStringContainsString("['rte.non_descriptive_link', 'rte.empty_link', 'rendered.empty_link']", $controller);
    }

    #[Test]
    public function ajaxRouteIsRegisteredForReadOnlySuggestion(): void
    {
        $routes = require __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';

        self::assertSame(
            '/a11y/ai/link-text-suggestion',
            $routes['a11y_ai_suggest_link_text']['path'] ?? '',
        );
        self::assertSame(['POST'], $routes['a11y_ai_suggest_link_text']['methods'] ?? []);
    }
}
