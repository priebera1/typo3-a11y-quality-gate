<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Anthropic\AnthropicMessagesProvider;
use Priebera\A11yQualityGate\Ai\OpenAi\OpenAiResponsesProvider;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Task\AiImageInput;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiLongContextTier;
use Priebera\A11yQualityGate\Ai\Usage\AiRates;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;

/** The worst-case bound a hard-stop budget reserves before a provider request. */
final class AiCostBoundTest extends TestCase
{
    #[Test]
    public function aStructuredRequestCountsEveryByteImageAndOutputCap(): void
    {
        $request = AiStructuredRequest::withUntrustedContext(
            AiTask::EXPLAIN,
            'v1',
            'Explain the finding.',
            ['text' => str_repeat('ä', 100)],
            ['name' => 'aqg_explain', 'schema' => ['type' => 'object']],
            700,
            AiImageInput::fromDataUrl(AiPromptDefinition::CONNECTION_TEST_IMAGE),
        );

        $openAi = AiCostBound::forStructuredRequest($request, 'openai');
        $anthropic = AiCostBound::forStructuredRequest($request, 'anthropic');

        $textBytes = strlen($request->instructions) + strlen($request->inputText) + strlen($request->schemaName) + strlen((string)json_encode($request->outputSchema));
        self::assertGreaterThan(200, strlen($request->inputText), 'Multi-byte characters count per byte.');
        self::assertSame($textBytes + AiCostBound::ENVELOPE_TOKENS + AiCostBound::IMAGE_TOKENS, $openAi->inputTokens);
        self::assertSame(700, $openAi->outputTokens);
        self::assertSame(700 + AiCostBound::ANTHROPIC_THINKING_HEADROOM_TOKENS, $anthropic->outputTokens);
    }

    #[Test]
    public function boundsMirrorTheProviderOutputCaps(): void
    {
        $anthropic = new \ReflectionClass(AnthropicMessagesProvider::class);
        $openAi = new \ReflectionClass(OpenAiResponsesProvider::class);

        self::assertSame($anthropic->getConstant('THINKING_HEADROOM_TOKENS'), AiCostBound::ANTHROPIC_THINKING_HEADROOM_TOKENS);
        self::assertSame($anthropic->getConstant('ALT_TEXT_MAX_OUTPUT_TOKENS'), AiCostBound::LEGACY_OUTPUT_TOKENS[AiTask::ALT_TEXT]);
        self::assertSame($anthropic->getConstant('LINK_TEXT_MAX_OUTPUT_TOKENS'), AiCostBound::LEGACY_OUTPUT_TOKENS[AiTask::LINK_TEXT]);
        self::assertSame($anthropic->getConstant('IFRAME_TITLE_MAX_OUTPUT_TOKENS'), AiCostBound::LEGACY_OUTPUT_TOKENS[AiTask::IFRAME_TITLE]);
        self::assertSame($openAi->getConstant('MAX_OUTPUT_TOKENS'), AiCostBound::LEGACY_OUTPUT_TOKENS[AiTask::ALT_TEXT]);
        self::assertSame($openAi->getConstant('MAX_OUTPUT_TOKENS'), AiCostBound::LEGACY_OUTPUT_TOKENS[AiTask::CONNECTION_TEST]);
        self::assertSame($openAi->getConstant('LINK_TEXT_MAX_OUTPUT_TOKENS'), AiCostBound::LEGACY_OUTPUT_TOKENS[AiTask::LINK_TEXT]);
        self::assertSame($openAi->getConstant('IFRAME_TITLE_MAX_OUTPUT_TOKENS'), AiCostBound::LEGACY_OUTPUT_TOKENS[AiTask::IFRAME_TITLE]);
        self::assertLessThanOrEqual(AiCostBound::FALLBACK_OUTPUT_TOKENS, 4096, 'AiStructuredRequest caps output at 4,096 tokens.');
    }

    #[Test]
    public function legacyTasksIncludeTheirFixedInstructionsAndImage(): void
    {
        $alt = AiCostBound::forLegacyTask(AiTask::ALT_TEXT, 'openai', '{"target_locale":"de"}', 1);

        self::assertSame(
            strlen(AiPromptDefinition::DEVELOPER_INSTRUCTIONS) + strlen(AiPromptDefinition::CONTEXT_WRAPPER_PREFIX)
                + strlen('{"target_locale":"de"}') + AiCostBound::ENVELOPE_TOKENS + AiCostBound::IMAGE_TOKENS,
            $alt->inputTokens,
        );
        self::assertSame(180, $alt->outputTokens);
        self::assertGreaterThan(AiCostBound::IMAGE_TOKENS, AiCostBound::forConnectionTest('anthropic')->inputTokens);
    }

    #[Test]
    public function theBoundCostUsesBothPrices(): void
    {
        $bound = new AiCostBound(10_000, 1_000);

        self::assertSame(20_000 + 10_000 + 3, $bound->costMicro(AiTestPrices::simple('anthropic', 'claude-sonnet-5-5', 2_000_000, 10_000_000)));
    }

    #[Test]
    public function theBoundNeverUnderestimatesCacheWritesOrPromptLengthTiers(): void
    {
        $bound = new AiCostBound(10_000, 1_000);
        // Every input token could be an automatic cache write: the higher write rate bounds it, never the cheap read rate.
        self::assertSame(25_000 + 10_000 + 3, $bound->costMicro(AiTestPrices::full(2_000_000, 10_000_000, 100_000, 2_500_000, 4_000_000)));

        $tier = new AiLongContextTier(100_000, new AiRates(500_000, 2_500_000, 50_000, 625_000, 1_000_000));
        $haiku = AiTestPrices::full(100_000, 500_000, 10_000, 125_000, 200_000, $tier, 'anthropic', 'claude-haiku-5-5');
        self::assertSame(1_250 + 500 + 3, (new AiCostBound(10_000, 1_000))->costMicro($haiku), 'Below the threshold: standard rates.');
        self::assertSame(12_500 + 500 + 3, (new AiCostBound(100_000, 1_000))->costMicro($haiku), 'Exactly at the threshold: still standard.');
        // 100,001 × $0.625 (long-prompt write rate) = 62,500.625 → 62,501 micro-USD, plus 1,000 × $2.50.
        self::assertSame(62_501 + 2_500 + 3, (new AiCostBound(100_001, 1_000))->costMicro($haiku), 'Above the threshold the whole request is billed at the long rates.');
    }

    #[Test]
    public function aSettlementAtTheBoundNeverExceedsTheReservation(): void
    {
        // Odd token counts and rates round up in five categories at settlement, in two for the bound.
        $price = AiTestPrices::full(1_000_001, 3_000_007, 100_003, 1_250_003, 2_000_011);
        foreach ([[1, 1], [999_999, 7], [123_457, 4_097]] as [$input, $output]) {
            $bound = new AiCostBound($input, $output);
            foreach ([[$input, 0, 0], [0, $input, 0], [0, 0, $input], [(int)($input / 3), (int)($input / 3), $input - 2 * (int)($input / 3)]] as [$ordinary, $read, $write]) {
                self::assertLessThanOrEqual($bound->costMicro($price), $price->costOfUsage(new AiUsage($ordinary, $output, true, $read, $write)));
            }
        }
    }
}
