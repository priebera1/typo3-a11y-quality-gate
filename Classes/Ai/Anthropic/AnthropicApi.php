<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Anthropic;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared HTTP conventions for the Anthropic Claude API: fixed base URL, API version header, no redirects, and safe
 * error extraction. The key is sent only in the x-api-key header and never logged.
 */
final class AnthropicApi
{
    public const BASE_URL = 'https://api.anthropic.com';
    public const API_VERSION = '2023-06-01';

    /** @return array<string,string> */
    public static function headers(#[\SensitiveParameter] string $apiKey, bool $json): array
    {
        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'Accept' => 'application/json',
        ];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    /** @return array{type:string,requestId:string} */
    public static function extractError(ResponseInterface $response): array
    {
        $type = '';
        try {
            $decoded = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
                $type = self::boundedDiagnosticValue($decoded['error']['type'] ?? '');
            }
        } catch (\JsonException) {
            $type = '';
        }

        return [
            'type' => $type,
            'requestId' => self::boundedDiagnosticValue($response->getHeaderLine('request-id')),
        ];
    }

    public static function boundedDiagnosticValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string)preg_replace('/[^a-zA-Z0-9._:-]+/', '_', (string)$value));

        return substr($value, 0, 80);
    }

    /** @param array<string,int|string> $metadata safe identifiers and codes only */
    public static function logSafeFailure(string $loggerName, string $event, array $metadata): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger($loggerName)
                ->warning($event, ['provider' => 'anthropic'] + $metadata);
        } catch (\Throwable) {
            // Diagnostics must never replace the safe failure path.
        }
    }
}
