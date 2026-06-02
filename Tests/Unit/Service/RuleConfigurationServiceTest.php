<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;

final class RuleConfigurationServiceTest extends TestCase
{
    #[Test]
    public function dictionarySettingsExposeStableSelectionFlags(): void
    {
        $service = $this->createSubject();

        $settings = $service->getDictionarySettingsFromRuleset([
            'rules_json' => '{"dictionary":{"mode":"force","forceLanguage":"DE"}}',
        ]);

        self::assertSame('force', $settings['mode']);
        self::assertSame('de', $settings['forceLanguage']);
        self::assertFalse($settings['isAuto']);
        self::assertTrue($settings['isForce']);
        self::assertFalse($settings['isDisable']);
    }

    #[Test]
    public function encodeRulesJsonWithDictionarySettingsPersistsModeAndForcedLanguage(): void
    {
        $service = $this->createSubject();

        $rulesJson = $service->encodeRulesJsonWithDictionarySettings('{}', [
            'mode' => 'force',
            'forceLanguage' => 'DE',
        ]);

        $settings = $service->getDictionarySettingsFromRuleset(['rules_json' => $rulesJson]);

        self::assertSame('force', $settings['mode']);
        self::assertSame('de', $settings['forceLanguage']);
        self::assertTrue($settings['isForce']);
    }


    #[Test]
    public function dictionarySettingsPreserveProjectSpecificListsWhenTextareaFieldsAreNotSubmitted(): void
    {
        $service = $this->createSubject();
        $currentRulesJson = json_encode([
            'dictionary' => [
                'mode' => 'auto',
                'forceLanguage' => '',
                'nonDescriptiveAdditional' => ['more details'],
                'nonDescriptiveDisabled' => ['click here'],
            ],
        ], JSON_THROW_ON_ERROR);

        $rulesJson = $service->encodeRulesJsonWithDictionarySettings($currentRulesJson, [
            'mode' => 'force',
            'forceLanguage' => 'DE',
        ]);
        $decoded = json_decode($rulesJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('force', $decoded['dictionary']['mode']);
        self::assertSame('de', $decoded['dictionary']['forceLanguage']);
        self::assertSame(['more details'], $decoded['dictionary']['nonDescriptiveAdditional']);
        self::assertSame(['click here'], $decoded['dictionary']['nonDescriptiveDisabled']);
    }

    #[Test]
    public function showProHintsCanBeStoredInRulesJson(): void
    {
        $service = $this->createSubject();

        $rulesJson = $service->encodeRulesJsonWithShowProHints('{}', false);

        self::assertFalse($service->getShowProHintsFromRuleset(['rules_json' => $rulesJson]));
    }

    #[Test]
    public function missingShowProHintsReturnsNullForExtensionConfigurationFallback(): void
    {
        $service = $this->createSubject();

        self::assertNull($service->getShowProHintsFromRuleset(['rules_json' => '{}']));
    }

    private function createSubject(): RuleConfigurationService
    {
        return new RuleConfigurationService($this->createStub(RulesetRepository::class));
    }
}
