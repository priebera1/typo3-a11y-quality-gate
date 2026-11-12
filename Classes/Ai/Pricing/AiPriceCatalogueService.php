<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Pricing;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Usage\AiLongContextTier;
use Priebera\A11yQualityGate\Ai\Usage\AiRates;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceCatalogueRepositoryInterface;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Keeps the local copy of the central AI price catalogue current. AQG's own service reads OpenAI's and Anthropic's
 * official pricing pages, validates them and publishes one verified catalogue (`GET /ai/pricing/v1/catalogue`,
 * contract `aqg.ai-price-catalogue.v1`); this installation only reads it. No customer key, licence key, installation id,
 * site or user data is sent, and an installation needs no Scheduler task, command or setting for it.
 *
 * When: on the first price lookup of a request (AiPriceTable) once the copy is REFRESH_AFTER_SECONDS old — a conditional
 * GET (If-None-Match), so an unchanged catalogue costs a 304. A failed refresh is retried after
 * RETRY_AFTER_FAILURE_SECONDS; an AQG service without the endpoint yet (HTTP 404, an extension upgraded before the
 * service) after RETRY_AFTER_MISSING_ENDPOINT_SECONDS. One refresh at a time (a database claim); a request never waits
 * longer than TIMEOUT_SECONDS for it, and any failure leaves the copy as it was.
 *
 * Trust: a catalogue is stored only when every entry is valid (all or nothing) and its version is not lower than the one
 * held (no rollback to an older catalogue). Per model, a price verified earlier than the one stored is not taken. Models
 * this extension does not support are ignored. Until a catalogue was stored, and for models it lacks, the reviewed
 * bundled catalogue prices (AiBundledPriceCatalogue); AiPriceTable always takes the later verified of the two.
 */
class AiPriceCatalogueService
{
    public const ENDPOINT = '/ai/pricing/v1/catalogue';
    public const SCHEMA = 'aqg.ai-price-catalogue.v1';
    public const REFRESH_AFTER_SECONDS = 6 * 3600;
    public const RETRY_AFTER_FAILURE_SECONDS = 3600;
    public const RETRY_AFTER_MISSING_ENDPOINT_SECONDS = 86400;
    public const LEASE_SECONDS = 60;
    public const TIMEOUT_SECONDS = 3.0;
    public const MAX_RESPONSE_BYTES = 262144;
    public const MAX_MODELS = 500;

    public const RESULT_UPDATED = 'updated';
    public const RESULT_NOT_MODIFIED = 'not_modified';
    public const RESULT_NOT_DUE = 'not_due';
    public const RESULT_BUSY = 'busy';
    public const RESULT_FAILED = 'failed';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly AiPriceCatalogueRepositoryInterface $repository,
        private readonly AiModelCompatibilityRegistry $registry,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @param array<string,mixed>|null $state */
    public static function isDue(?array $state, int $now): bool
    {
        if ($state === null) {
            return true;
        }
        $lastAttempt = (int)($state['last_attempt_at'] ?? 0);
        if ((int)($state['consecutive_failures'] ?? 0) > 0) {
            $wait = (string)($state['error_reason'] ?? '') === 'endpoint_missing' ? self::RETRY_AFTER_MISSING_ENDPOINT_SECONDS : self::RETRY_AFTER_FAILURE_SECONDS;

            return $lastAttempt < $now - $wait;
        }

        return (int)($state['refreshed_at'] ?? 0) < $now - self::REFRESH_AFTER_SECONDS;
    }

    /** @return string one of the RESULT_* codes; never throws */
    public function refreshIfDue(?int $now = null): string
    {
        $now ??= time();
        try {
            if (!self::isDue($this->repository->findState(), $now)) {
                return self::RESULT_NOT_DUE;
            }
            $token = $this->repository->claim($now, self::LEASE_SECONDS);
        } catch (\Throwable $exception) {
            // Not migrated yet, or no database: the bundled catalogue prices meanwhile.
            $this->log('ai_price_catalogue_state_unavailable', $exception::class);
            return self::RESULT_FAILED;
        }
        if ($token === null) {
            return self::RESULT_BUSY;
        }

        try {
            // Re-read under the claim: a refresh that finished meanwhile is not repeated.
            $state = $this->repository->findState();
            if (!self::isDue($state, $now)) {
                return self::RESULT_NOT_DUE;
            }

            return $this->refresh($token, $state, $now);
        } catch (\Throwable $exception) {
            $reason = $exception instanceof AiPriceCatalogueException ? $exception->reason : 'error';
            try {
                $this->repository->storeFailure($token, $now, $reason);
            } catch (\Throwable) {
            }
            $this->log('ai_price_catalogue_refresh_failed', $reason);

            return self::RESULT_FAILED;
        } finally {
            try {
                $this->repository->releaseClaim($token);
            } catch (\Throwable) {
                // The lease expires on its own.
            }
        }
    }

    /** @param array<string,mixed>|null $state */
    private function refresh(string $token, ?array $state, int $now): string
    {
        $etag = (string)($state['etag'] ?? '');
        $headers = ['Accept' => 'application/json'];
        if ($etag !== '' && (int)($state['catalogue_version'] ?? 0) > 0) {
            $headers['If-None-Match'] = $etag;
        }
        try {
            $response = $this->requestFactory->request(rtrim(ProSettings::resolveApiBaseUrl(), '/') . self::ENDPOINT, 'GET', [
                'headers' => $headers,
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable) {
            throw new AiPriceCatalogueException('transport_error');
        }

        $status = $response->getStatusCode();
        if ($status === 304) {
            $this->repository->markCurrent($token, $now);
            return self::RESULT_NOT_MODIFIED;
        }
        if ($status === 404) {
            // An AQG service older than this extension (endpoint not deployed yet): check again tomorrow.
            throw new AiPriceCatalogueException('endpoint_missing');
        }
        if ($status !== 200) {
            throw new AiPriceCatalogueException($status === 503 ? 'catalogue_unavailable' : 'http_' . $status);
        }
        $body = (string)$response->getBody();
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new AiPriceCatalogueException('invalid_body');
        }
        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiPriceCatalogueException('invalid_json');
        }

        $catalogue = $this->validate($payload, $now);
        $heldVersion = (int)($state['catalogue_version'] ?? 0);
        if ($catalogue['version'] < $heldVersion) {
            throw new AiPriceCatalogueException('version_regressed');
        }

        $prices = [];
        foreach ($catalogue['prices'] as $price) {
            $stored = $this->repository->findPrice($price['provider'], $price['model']);
            if ($stored !== null && (int)($stored['verified_at'] ?? 0) > $price['verified_at']) {
                // Never replace a later verified price with an earlier one: the stored price stays as it is.
                $price = ['rates' => (string)$stored['rates'], 'source_url' => (string)$stored['source_url'], 'verified_at' => (int)$stored['verified_at'], 'label' => (string)$stored['label']] + $price;
            }
            $prices[] = $price;
        }
        $responseEtag = trim($response->getHeaderLine('ETag'));
        if (!$this->repository->storeCatalogue($token, $catalogue['version'], $responseEtag, $catalogue['publishedAt'], $prices, $now)) {
            return self::RESULT_BUSY;
        }

        return self::RESULT_UPDATED;
    }

    /**
     * Strict validation of contract `aqg.ai-price-catalogue.v1`; any malformed entry refuses the whole catalogue.
     *
     * @return array{version:int,publishedAt:int,prices:list<array{provider:string,model:string,label:string,rates:string,source_url:string,verified_at:int,listed:bool}>}
     * @throws AiPriceCatalogueException
     */
    public function validate(mixed $payload, int $now): array
    {
        $catalogue = is_array($payload) && ($payload['success'] ?? null) === true ? ($payload['catalogue'] ?? null) : null;
        if (!is_array($catalogue)
            || ($catalogue['schema'] ?? null) !== self::SCHEMA
            || ($catalogue['currency'] ?? null) !== 'USD'
            || ($catalogue['unit'] ?? null) !== 'micro_usd_per_1m_tokens') {
            throw new AiPriceCatalogueException('invalid_contract');
        }
        $version = $catalogue['version'] ?? null;
        $publishedAt = self::timestamp($catalogue['publishedAt'] ?? null, $now);
        $models = $catalogue['models'] ?? null;
        if (!is_int($version) || $version < 1 || $publishedAt === null || !is_array($models) || !array_is_list($models) || count($models) > self::MAX_MODELS) {
            throw new AiPriceCatalogueException('invalid_contract');
        }

        $supported = [];
        foreach (AiProviderCatalog::ids() as $provider) {
            $supported[$provider] = array_flip($this->registry->priceModelIds($provider));
        }
        $prices = [];
        foreach ($models as $entry) {
            $price = $this->entry($entry, $now);
            $key = $price['provider'] . "\0" . $price['model'];
            if (isset($prices[$key])) {
                throw new AiPriceCatalogueException('invalid_model');
            }
            if (isset($supported[$price['provider']][$price['model']])) {
                $prices[$key] = $price;
            }
        }
        if ($prices === []) {
            throw new AiPriceCatalogueException('no_supported_model');
        }

        return ['version' => $version, 'publishedAt' => $publishedAt, 'prices' => array_values($prices)];
    }

    /** @return array{provider:string,model:string,label:string,rates:string,source_url:string,verified_at:int,listed:bool} */
    private function entry(mixed $entry, int $now): array
    {
        if (!is_array($entry)) {
            throw new AiPriceCatalogueException('invalid_model');
        }
        $provider = $entry['provider'] ?? null;
        $model = $entry['model'] ?? null;
        $label = $entry['label'] ?? null;
        $sourceUrl = $entry['sourceUrl'] ?? null;
        $listed = $entry['listed'] ?? null;
        $verifiedAt = self::timestamp($entry['verifiedAt'] ?? null, $now);
        if (!is_string($provider) || !in_array($provider, AiProviderCatalog::ids(), true)
            || !is_string($model) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,99}$/', $model) !== 1
            || !is_string($label) || mb_strlen($label) > 100
            || !is_string($sourceUrl) || !str_starts_with($sourceUrl, 'https://') || strlen($sourceUrl) > 255
            || !is_bool($listed) || $verifiedAt === null) {
            throw new AiPriceCatalogueException('invalid_model');
        }
        $rates = self::rates($entry['rates'] ?? null);
        $tierData = $entry['longContext'] ?? null;
        $tier = null;
        if ($tierData !== null) {
            if (!is_array($tierData) || !is_int($tierData['threshold'] ?? null)) {
                throw new AiPriceCatalogueException('invalid_rates');
            }
            $tier = AiLongContextTier::fromArray(['threshold' => $tierData['threshold'], 'rates' => self::rates($tierData['rates'] ?? null)->toArray()]);
            if ($tier === null) {
                throw new AiPriceCatalogueException('invalid_rates');
            }
            foreach (array_map(null, $rates->values(), $tier->rates->values()) as [$short, $long]) {
                if ($long < $short || $long > 10 * $short) {
                    throw new AiPriceCatalogueException('invalid_rates');
                }
            }
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'label' => $label,
            'rates' => (string)json_encode(['standard' => $rates->toArray(), 'long_context' => $tier?->toArray()], JSON_THROW_ON_ERROR),
            'source_url' => $sourceUrl,
            'verified_at' => $verifiedAt,
            'listed' => $listed,
        ];
    }

    private static function rates(mixed $rates): AiRates
    {
        if (!is_array($rates)) {
            throw new AiPriceCatalogueException('invalid_rates');
        }
        $parsed = AiRates::fromArray([
            'input' => $rates['input'] ?? null,
            'output' => $rates['output'] ?? null,
            'cached_input' => $rates['cachedInput'] ?? null,
            'cache_write' => $rates['cacheWrite'] ?? null,
            'cache_write_extended' => $rates['cacheWriteExtended'] ?? null,
        ]);
        if ($parsed === null) {
            throw new AiPriceCatalogueException('invalid_rates');
        }

        return $parsed;
    }

    /** A UTC ISO 8601 time as the service writes it (2026-11-02T13:42:03.000Z), not later than a day from now (clock skew), or null. */
    private static function timestamp(mixed $value, int $now): ?int
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(str_contains($value, '.') ? 'Y-m-d\TH:i:s.u\Z' : 'Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($date === false || \DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }
        $time = $date->getTimestamp();

        return $time > 0 && $time <= $now + 86400 ? $time : null;
    }

    private function log(string $message, string $reason): void
    {
        try {
            $this->logger?->info($message, ['reason' => $reason]);
        } catch (\Throwable) {
        }
    }
}
