<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;

final class AiModelCacheCodecTest extends TestCase
{
    #[Test]
    public function cacheIsBoundToSafeKeyFingerprint(): void
    {
        $fingerprint = str_repeat('a', 64);
        $subject = new AiModelCacheCodec();

        $decoded = $subject->decode($subject->encode([
            ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini'],
        ], ['gpt-audio-1'], $fingerprint));

        self::assertTrue($decoded['valid']);
        self::assertSame($fingerprint, $decoded['keyFingerprint']);
        self::assertSame('gpt-4.1-mini', $decoded['supported'][0]['id']);
        self::assertSame(['gpt-audio-1'], $decoded['unsupported']);
    }

    #[Test]
    public function missingOrInvalidFingerprintInvalidatesCache(): void
    {
        $subject = new AiModelCacheCodec();

        self::assertFalse($subject->decode('{"registry_version":"aqg_openai_models_v1","supported":[],"unsupported":[]}')['valid']);
        self::assertFalse($subject->decode('{"registry_version":"aqg_openai_models_v1","key_fingerprint":"secret","supported":[],"unsupported":[]}')['valid']);
    }
}
