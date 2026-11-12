<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Pricing;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Usage\AiLongContextTier;
use Priebera\A11yQualityGate\Ai\Usage\AiPrice;
use Priebera\A11yQualityGate\Ai\Usage\AiRates;

/**
 * The reviewed price catalogue shipped with this AQG release, so a new installation prices every supported model
 * without any setup and without network access. Read from the official pricing pages on VERIFIED_AT with the AQG
 * service's parsers (aqg-api services/ai-pricing; its test pins the same figures) and reviewed by hand.
 * Micro-USD per 1M tokens.
 *
 * A price from the AQG service's central catalogue (AiPriceCatalogueService) replaces a bundled one as soon as it was
 * verified later; the bundled price is what an installation uses before it reached the service, shown with its date so
 * its age is visible. Update this catalogue (and its date) with every release that changes the model registry.
 */
final class AiBundledPriceCatalogue
{
    /** The official pages the figures were read from (the AQG service reads the same pages). */
    public const OPENAI_SOURCE_URL = 'https://developers.openai.com/api/docs/pricing.md';
    public const ANTHROPIC_SOURCE_URL = 'https://platform.claude.com/docs/en/about-claude/pricing.md';

    /** 2026-11-02 13:42:03 UTC: when both pricing pages were read for this catalogue. */
    public const VERIFIED_AT = 1793626923;

    private const MODELS = [
        AiProviderCatalog::OPENAI => [
            'gpt-4.1-mini' => [
                'standard' => ['input' => 400_000, 'output' => 1_600_000, 'cached_input' => 100_000, 'cache_write' => 400_000, 'cache_write_extended' => 400_000],
                'long_context' => null,
            ],
            'gpt-5.4-mini' => [
                'standard' => ['input' => 750_000, 'output' => 4_500_000, 'cached_input' => 75_000, 'cache_write' => 750_000, 'cache_write_extended' => 750_000],
                'long_context' => null,
            ],
            'gpt-5.4-nano' => [
                'standard' => ['input' => 200_000, 'output' => 1_250_000, 'cached_input' => 20_000, 'cache_write' => 200_000, 'cache_write_extended' => 200_000],
                'long_context' => null,
            ],
        ],
        AiProviderCatalog::ANTHROPIC => [
            'claude-haiku-4-5' => [
                'standard' => ['input' => 1_000_000, 'output' => 5_000_000, 'cached_input' => 100_000, 'cache_write' => 1_250_000, 'cache_write_extended' => 2_000_000],
                'long_context' => null,
            ],
            'claude-haiku-5-5' => [
                'standard' => ['input' => 100_000, 'output' => 500_000, 'cached_input' => 10_000, 'cache_write' => 125_000, 'cache_write_extended' => 200_000],
                'long_context' => ['threshold' => 100_000, 'rates' => ['input' => 500_000, 'output' => 2_500_000, 'cached_input' => 50_000, 'cache_write' => 625_000, 'cache_write_extended' => 1_000_000]],
            ],
            'claude-opus-4-8' => [
                'standard' => ['input' => 5_000_000, 'output' => 25_000_000, 'cached_input' => 500_000, 'cache_write' => 6_250_000, 'cache_write_extended' => 10_000_000],
                'long_context' => null,
            ],
            'claude-opus-5' => [
                'standard' => ['input' => 5_000_000, 'output' => 25_000_000, 'cached_input' => 500_000, 'cache_write' => 6_250_000, 'cache_write_extended' => 10_000_000],
                'long_context' => null,
            ],
            'claude-opus-5-5' => [
                'standard' => ['input' => 4_000_000, 'output' => 20_000_000, 'cached_input' => 200_000, 'cache_write' => 5_000_000, 'cache_write_extended' => 8_000_000],
                'long_context' => null,
            ],
            'claude-sonnet-5' => [
                'standard' => ['input' => 2_000_000, 'output' => 10_000_000, 'cached_input' => 200_000, 'cache_write' => 2_500_000, 'cache_write_extended' => 4_000_000],
                'long_context' => null,
            ],
            'claude-sonnet-5-5' => [
                'standard' => ['input' => 2_000_000, 'output' => 10_000_000, 'cached_input' => 100_000, 'cache_write' => 2_500_000, 'cache_write_extended' => 4_000_000],
                'long_context' => null,
            ],
        ],
    ];

    public static function find(string $provider, string $priceModelId): ?AiPrice
    {
        $provider = AiProviderCatalog::normalize($provider);
        $entry = self::MODELS[$provider][$priceModelId] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $rates = AiRates::fromArray($entry['standard']);
        $tier = $entry['long_context'] !== null ? AiLongContextTier::fromArray($entry['long_context']) : null;
        if ($rates === null || ($entry['long_context'] !== null && $tier === null)) {
            return null;
        }

        return new AiPrice(
            $provider,
            $priceModelId,
            $rates,
            $tier,
            AiPrice::ORIGIN_BUNDLED,
            self::sourceUrl($provider),
            self::VERIFIED_AT,
            true,
        );
    }

    /** @return list<string> */
    public static function modelIds(string $provider): array
    {
        return array_keys(self::MODELS[AiProviderCatalog::normalize($provider)] ?? []);
    }

    public static function sourceUrl(string $provider): string
    {
        return AiProviderCatalog::normalize($provider) === AiProviderCatalog::ANTHROPIC ? self::ANTHROPIC_SOURCE_URL : self::OPENAI_SOURCE_URL;
    }
}
