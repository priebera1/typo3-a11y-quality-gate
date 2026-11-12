<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiKeyFingerprintService;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;

/**
 * The registry and model cache are scoped per provider; OpenAI keeps its 2.x registry version so upgraded caches
 * stay valid.
 */
final class AiProviderModelRegistryTest extends TestCase
{
    #[Test]
    public function claudeModelsAreSupportedOnlyForAnthropic(): void
    {
        $registry = new AiModelCompatibilityRegistry();

        self::assertTrue($registry->supports('claude-sonnet-5-5', 'anthropic'));
        self::assertFalse($registry->supports('claude-sonnet-5-5', 'openai'));
        self::assertFalse($registry->supports('gpt-5.4-mini', 'anthropic'));
        self::assertTrue($registry->supports('gpt-5.4-mini', 'openai'));
        self::assertSame('aqg_openai_models_v1', AiModelCompatibilityRegistry::VERSION);
    }

    #[Test]
    public function anthropicDiscoveryIntersectsRegistryAndReportedCapabilities(): void
    {
        $filtered = (new AiModelCompatibilityRegistry())->filterAvailable(
            ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5', 'claude-sonnet-4-5-20250929', 'gpt-5.4-mini'],
            'anthropic',
            [
                'claude-haiku-5-5' => ['imageInput' => true, 'structuredOutputs' => true],
                'claude-opus-5-5' => ['imageInput' => true, 'structuredOutputs' => false],
            ],
        );

        self::assertSame([
            ['id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5'],
            ['id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5'],
        ], $filtered['supported']);
        self::assertSame(['claude-opus-5-5', 'claude-sonnet-4-5-20250929', 'gpt-5.4-mini'], $filtered['unsupported']);
    }

    #[Test]
    public function aModelCacheIsValidOnlyForTheProviderThatWroteIt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
        $codec = new AiModelCacheCodec();
        $fingerprint = (new AiKeyFingerprintService())->fingerprint('sk-ant-key', 'anthropic');
        $anthropicCache = $codec->encode([['id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5']], [], $fingerprint, 'anthropic');
        $openAiCache = $codec->encode([['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 mini']], [], $fingerprint);

        self::assertTrue($codec->decode($anthropicCache, 'anthropic')['valid']);
        self::assertFalse($codec->decode($anthropicCache)['valid']);
        self::assertFalse($codec->decode($openAiCache, 'anthropic')['valid']);
        self::assertTrue($codec->decode($openAiCache)['valid']);
        self::assertStringContainsString('"registry_version":"aqg_openai_models_v1"', $openAiCache);
    }
}
