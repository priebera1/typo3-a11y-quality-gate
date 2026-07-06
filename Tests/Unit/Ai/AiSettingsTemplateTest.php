<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiSettingsTemplateTest extends TestCase
{
    #[Test]
    public function modelIsShownOnceAsProjectFilteredAdminSelection(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        self::assertStringContainsString('id="aqg-ai-model"', $template);
        self::assertStringContainsString('data-aqg-ai-model="true"', $template);
        self::assertStringContainsString('{aiConfigurationStatus.availableModels}', $template);
        self::assertStringContainsString('{aiConfigurationStatus.unsupportedModels}', $template);
        self::assertStringContainsString('Refresh available models', $template);
        self::assertStringContainsString('Select an OpenAI model', $template);
        self::assertStringNotContainsString('aqg-ai-model-readonly', $template);
        self::assertStringNotContainsString('{aiModelName}', $template);
        self::assertStringNotContainsString('{aiModelId}', $template);
        self::assertStringNotContainsString('Recommended', $template);
        self::assertStringNotContainsString('Preferred', $template);
        self::assertStringNotContainsString('fixed AQG model', $template);
        self::assertSame(1, substr_count($template, 'settings.ai.selectedModel'));
    }

    #[Test]
    public function keyModelAndConnectionActionsHaveSeparateEndpoints(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        self::assertStringContainsString('data-save-url="{aiSettingsSaveUrl}"', $template);
        self::assertStringContainsString('data-refresh-models-url="{aiSettingsRefreshModelsUrl}"', $template);
        self::assertStringContainsString('data-select-model-url="{aiSettingsSelectModelUrl}"', $template);
        self::assertStringContainsString('data-test-url="{aiSettingsTestUrl}"', $template);
        self::assertStringContainsString('Models endpoint', $template);
        self::assertStringContainsString('Responses', $template);
    }

    #[Test]
    public function summaryExposesOneSharedAjaxUiStateContract(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Partials/Settings/TabAi.html',
        );
        self::assertIsString($template);

        self::assertStringContainsString('data-connection-status="{aiConfigurationStatus.status}"', $template);
        self::assertStringContainsString('data-error-code="{aiConfigurationStatus.errorCode}"', $template);
        self::assertStringContainsString('data-aqg-ai-status-badge="true"', $template);
        self::assertStringContainsString('data-aqg-ai-last-tested="true"', $template);
        self::assertStringContainsString('data-aqg-ai-last-verified="true"', $template);
        self::assertStringContainsString('data-aqg-ai-persistent-status="true"', $template);
        self::assertStringContainsString('data-aqg-ai-unsupported-models="true"', $template);
        self::assertStringContainsString('data-aqg-ai-unsupported-count="true"', $template);
        self::assertStringContainsString('data-aqg-ai-unsupported-list="true"', $template);
        self::assertStringContainsString('aiConfigurationStatus.actions.testConnectionEnabled', $template);
    }
}
