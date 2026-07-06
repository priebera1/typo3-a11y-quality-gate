<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Service\AiSettingsUiStateBuilder;

final class AiSettingsUiStateBuilderTest extends TestCase
{
    #[Test]
    public function connectionFailureDisablesTestActionAndPreservesVerificationMetadata(): void
    {
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->with('main')->willReturn([
            'configured' => true,
            'selectedModelAvailable' => true,
            'selectedModelId' => 'gpt-4.1-mini',
            'availableModels' => [['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini']],
            'connectionStatus' => 'connection_failed',
            'lastTestErrorCode' => 'model_not_permitted',
            'lastTestedAt' => 1720000000,
            'lastVerifiedAt' => 0,
        ]);

        $state = (new AiSettingsUiStateBuilder($resolver))->build('main');

        self::assertSame('connection_failed', $state['status']);
        self::assertSame('model_not_permitted', $state['errorCode']);
        self::assertSame(1720000000, $state['lastTestedAt']);
        self::assertSame(0, $state['lastVerifiedAt']);
        self::assertTrue($state['actions']['refreshModelsEnabled']);
        self::assertFalse($state['actions']['testConnectionEnabled']);
    }

    #[Test]
    public function modelCollectionsAreAlwaysPresentAndNormalized(): void
    {
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->willReturn([
            'configured' => true,
            'selectedModelAvailable' => false,
            'connectionStatus' => 'not_verified',
        ]);

        $state = (new AiSettingsUiStateBuilder($resolver))->build('main');

        self::assertArrayHasKey('availableModels', $state);
        self::assertArrayHasKey('unsupportedModels', $state);
        self::assertSame([], $state['availableModels']);
        self::assertSame([], $state['unsupportedModels']);
    }

    #[Test]
    public function unsupportedModelsAreTrimmedAndDeduplicated(): void
    {
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->willReturn([
            'configured' => true,
            'selectedModelAvailable' => true,
            'connectionStatus' => 'not_verified',
            'unsupportedModels' => [
                'gpt-4o-mini',
                ' gpt-4o-mini ',
                '',
                'whisper-1',
            ],
        ]);

        $state = (new AiSettingsUiStateBuilder($resolver))->build('main');

        self::assertSame(['gpt-4o-mini', 'whisper-1'], $state['unsupportedModels']);
    }

    #[Test]
    public function verifiedConfigurationKeepsTestActionEnabled(): void
    {
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('status')->willReturn([
            'configured' => true,
            'selectedModelAvailable' => true,
            'connectionStatus' => 'connected',
            'lastTestErrorCode' => '',
            'lastTestedAt' => 1720000000,
            'lastVerifiedAt' => 1720000000,
        ]);

        $state = (new AiSettingsUiStateBuilder($resolver))->build('main');

        self::assertSame('connected', $state['status']);
        self::assertSame('', $state['errorCode']);
        self::assertTrue($state['actions']['testConnectionEnabled']);
    }
}
