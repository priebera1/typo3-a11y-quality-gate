<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;

final class AiModelCompatibilityRegistryTest extends TestCase
{
    #[Test]
    public function registryHasNoPreferredOrDefaultModel(): void
    {
        $registry = new AiModelCompatibilityRegistry();
        $models = $registry->all();

        self::assertArrayHasKey('gpt-4.1-mini', $models);
        self::assertArrayHasKey('gpt-5.4-mini', $models);
        self::assertArrayHasKey('gpt-5.4-nano', $models);
        self::assertArrayNotHasKey('default', $models);
        self::assertStringNotContainsString('recommended', strtolower(json_encode($models, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString('preferred', strtolower(json_encode($models, JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function availableModelsAreFilteredDeduplicatedAndSorted(): void
    {
        $filtered = (new AiModelCompatibilityRegistry())->filterAvailable([
            'gpt-audio-1',
            'gpt-5.4-mini',
            'gpt-4.1-mini',
            'gpt-5.4-mini',
            'text-embedding-3-small',
        ]);

        self::assertSame([
            ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini'],
            ['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 mini'],
        ], $filtered['supported']);
        self::assertSame(['gpt-audio-1', 'text-embedding-3-small'], $filtered['unsupported']);
    }

    #[Test]
    public function nonReasoningAndReasoningProfilesAreExplicit(): void
    {
        $registry = new AiModelCompatibilityRegistry();

        self::assertFalse($registry->require('gpt-4.1-mini')['reasoningParameter']);
        self::assertTrue($registry->require('gpt-5.4-mini')['reasoningParameter']);
        self::assertContains('none', $registry->require('gpt-5.4-mini')['supportedReasoningEfforts']);
        self::assertContains('low', $registry->require('gpt-4.1-mini')['imageDetail']);
    }

    #[Test]
    public function unknownModelIsNeverSelectable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AiModelCompatibilityRegistry())->require('gpt-unknown');
    }
}
