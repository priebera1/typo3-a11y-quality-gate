<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * N-03 (final customer acceptance): editors with options.a11y_quality_gate.editRecord = 0 saw "Ignore…" on every
 * occurrence (and Ignore selected / Ignore all on this page); the server refused each one with "Access denied.". Every
 * ignore and unignore control is now rendered only with the controller's canIgnore (the same flag the server enforces).
 */
final class PageDetailIgnorePermissionTemplateTest extends TestCase
{
    #[Test]
    public function everyIgnoreControlIsGatedByCanIgnore(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/PageDetail/Show.html');

        foreach (['a11y-ignore-trigger', 'a11y-bulk-open-confirm', 'a11y-bulk-open-rule-page', 'action="{unignoreUrl}"'] as $control) {
            $position = strpos($template, $control);
            self::assertNotFalse($position, $control);
            $before = substr($template, max(0, $position - 700), 700);
            self::assertMatchesRegularExpression('/<f:if condition="[^"]*\{canIgnore\}[^"]*">(?:(?!<\/f:if>).)*$/s', $before, $control . ' must be inside a {canIgnore} condition.');
        }
        self::assertStringContainsString('<f:if condition="{canIgnoreRuleOnSite}">', $template);
    }

    #[Test]
    public function theControllerPassesTheServerSideFlag(): void
    {
        $controller = (string)file_get_contents(__DIR__ . '/../../../Classes/Controller/PageDetailController.php');

        self::assertStringContainsString("\$canIgnore = \$this->accessControlService->canEditRecord(\$backendUser);", $controller);
        self::assertStringContainsString("'canIgnoreRuleOnSite' => \$canShowSettings && \$canIgnore,", $controller);
        self::assertStringContainsString("'canIgnore' => \$canIgnore,", $controller);
    }
}
