<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Provider;

/**
 * The AI providers AQG can talk to. One provider is active per TYPO3 site; every provider keeps its own encrypted
 * key, model choice and verification, so switching never removes the other provider's configuration.
 */
final class AiProviderCatalog
{
    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';

    /** Sites configured before AQG 3.0 have no stored provider choice and keep using OpenAI. */
    public const LEGACY_DEFAULT = self::OPENAI;

    /**
     * environmentVariable: an installation-wide key that overrides site keys. Only OpenAI has one
     * (AQG_OPENAI_API_KEY, since 2.0); an Anthropic counterpart is an open owner decision.
     *
     * @var array<string,array{label:string,environmentVariable:string,keyPrefix:string}>
     */
    private const PROVIDERS = [
        self::OPENAI => [
            'label' => 'OpenAI',
            'environmentVariable' => 'AQG_OPENAI_API_KEY',
            'keyPrefix' => 'sk-',
        ],
        self::ANTHROPIC => [
            'label' => 'Anthropic Claude',
            'environmentVariable' => '',
            'keyPrefix' => 'sk-ant-',
        ],
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function isSupported(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    /** Returns the canonical provider id, or '' for anything AQG does not support. */
    public static function normalize(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return self::isSupported($provider) ? $provider : '';
    }

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider]['label'] ?? '';
    }

    public static function environmentVariable(string $provider): string
    {
        return self::PROVIDERS[$provider]['environmentVariable'] ?? '';
    }

    public static function environmentKey(string $provider): string
    {
        $variable = self::environmentVariable($provider);

        return $variable !== '' ? trim((string)getenv($variable)) : '';
    }

    /**
     * A cheap plausibility check before a key is stored: it catches a key pasted into the wrong provider's field.
     * The provider remains the authority on whether a key is valid.
     */
    public static function isPlausibleKey(string $provider, #[\SensitiveParameter] string $apiKey): bool
    {
        $prefix = self::PROVIDERS[$provider]['keyPrefix'] ?? null;
        if ($prefix === null || strlen($apiKey) < 20 || strlen($apiKey) > 512) {
            return false;
        }
        if (preg_match('/^[\x21-\x7e]+$/', $apiKey) !== 1 || !str_starts_with($apiKey, $prefix)) {
            return false;
        }
        if ($provider === self::OPENAI && str_starts_with($apiKey, 'sk-ant-')) {
            return false;
        }

        // Anthropic Admin API keys cannot call the Messages API.
        return !($provider === self::ANTHROPIC && str_starts_with($apiKey, 'sk-ant-admin'));
    }
}
