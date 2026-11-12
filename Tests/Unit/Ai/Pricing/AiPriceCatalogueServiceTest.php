<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Pricing;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Pricing\AiBundledPriceCatalogue;
use Priebera\A11yQualityGate\Ai\Pricing\AiPriceCatalogueService;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Usage\AiPrice;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceRepositoryInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * The installation as a pricing consumer: it reads the AQG service's verified catalogue, validates it strictly, keeps a
 * local copy with conditional refresh, back-off and a claim, and never trusts less than it holds.
 */
final class AiPriceCatalogueServiceTest extends TestCase
{
    private InMemoryAiPriceCatalogueRepository $repository;
    private string|false $apiBaseUrl = false;
    /** The real clock: AiPriceTable refreshes at time(), and the catalogue is verified after the bundled list. */
    private int $now = 0;
    /** @var list<array{url:string,headers:array<string,string>}> */
    private array $requests = [];
    /** @var list<Response|\Throwable> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = max(time(), AiBundledPriceCatalogue::VERIFIED_AT + 3600);
        $this->apiBaseUrl = getenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=https://api.example.test');
        $this->repository = new InMemoryAiPriceCatalogueRepository();
    }

    protected function tearDown(): void
    {
        putenv($this->apiBaseUrl === false ? 'A11Y_QUALITY_GATE_PRO_API_BASE_URL' : 'A11Y_QUALITY_GATE_PRO_API_BASE_URL=' . $this->apiBaseUrl);
        parent::tearDown();
    }

    #[Test]
    public function aFreshInstallationLoadsTheCatalogueAndPrefersItsNewerPrices(): void
    {
        self::assertSame(AiPrice::ORIGIN_BUNDLED, $this->table(false)->find('openai', 'gpt-5.4-mini')?->origin, 'Before any refresh: the bundled list.');
        $this->responses = [$this->catalogue(3, ['gpt-5.4-mini' => 800_000])];

        $price = $this->table()->find('openai', 'gpt-5.4-mini-2026-03-17');

        self::assertSame(AiPrice::ORIGIN_CENTRAL, $price?->origin);
        self::assertSame(800_000, $price?->rates->input);
        self::assertTrue($price?->complete);
        self::assertSame('https://api.example.test/ai/pricing/v1/catalogue', $this->requests[0]['url']);
        self::assertArrayNotHasKey('If-None-Match', $this->requests[0]['headers']);
        self::assertSame(3, $this->repository->state['catalogue_version']);
        self::assertSame('"aqg-ai-prices-3"', $this->repository->state['etag']);
        self::assertArrayNotHasKey("openai\0gpt-4o", $this->repository->prices, 'Models this extension does not support are ignored.');
    }

    #[Test]
    public function aCurrentCopyIsNotFetchedAgainAndAnUnchangedCatalogueCostsA304(): void
    {
        $this->responses = [$this->catalogue(3)];
        $service = $this->service();
        self::assertSame(AiPriceCatalogueService::RESULT_UPDATED, $service->refreshIfDue($this->now));
        self::assertSame(AiPriceCatalogueService::RESULT_NOT_DUE, $service->refreshIfDue($this->now + 3600));
        self::assertCount(1, $this->requests);

        $this->responses = [new Response(304)];
        $later = $this->now + AiPriceCatalogueService::REFRESH_AFTER_SECONDS + 1;
        self::assertSame(AiPriceCatalogueService::RESULT_NOT_MODIFIED, $service->refreshIfDue($later));
        self::assertSame('"aqg-ai-prices-3"', $this->requests[1]['headers']['If-None-Match']);
        self::assertSame($later, $this->repository->state['refreshed_at']);
    }

    #[Test]
    public function anOutageKeepsTheCopyRetriesHourlyAndRecovers(): void
    {
        $this->responses = [$this->catalogue(3, ['gpt-5.4-mini' => 800_000])];
        $service = $this->service();
        $service->refreshIfDue($this->now);
        $copy = $this->repository->prices;

        $due = $this->now + AiPriceCatalogueService::REFRESH_AFTER_SECONDS + 1;
        foreach ([new \RuntimeException('cURL error 28'), new Response(503), new Response(500), new Response(200, ['Content-Type' => 'text/html'], '<html>maintenance</html>')] as $index => $failure) {
            $this->responses = [$failure];
            $at = $due + $index * (AiPriceCatalogueService::RETRY_AFTER_FAILURE_SECONDS + 1);
            self::assertSame(AiPriceCatalogueService::RESULT_FAILED, $service->refreshIfDue($at));
            self::assertSame($copy, $this->repository->prices, 'The verified copy stays.');
            self::assertSame(AiPriceCatalogueService::RESULT_NOT_DUE, $service->refreshIfDue($at + 60), 'No retry on every request.');
        }
        self::assertSame(4, $this->repository->state['consecutive_failures']);
        self::assertSame(800_000, $this->table(false)->find('openai', 'gpt-5.4-mini')?->rates->input, 'Hard stops keep the last verified central price.');

        $this->responses = [$this->catalogue(4, ['gpt-5.4-mini' => 820_000])];
        self::assertSame(AiPriceCatalogueService::RESULT_UPDATED, $service->refreshIfDue($at + AiPriceCatalogueService::RETRY_AFTER_FAILURE_SECONDS + 1));
        self::assertSame(0, $this->repository->state['consecutive_failures']);
        self::assertSame(820_000, $this->table(false)->find('openai', 'gpt-5.4-mini')?->rates->input);
    }

    #[Test]
    public function anAqgServiceWithoutTheEndpointIsAskedOnlyDailyAndTheBundledListPrices(): void
    {
        $this->responses = [new Response(404, [], '{"success":false}')];
        $service = $this->service();

        self::assertSame(AiPriceCatalogueService::RESULT_FAILED, $service->refreshIfDue($this->now));
        self::assertSame('endpoint_missing', $this->repository->state['error_reason']);
        self::assertSame(AiPriceCatalogueService::RESULT_NOT_DUE, $service->refreshIfDue($this->now + 2 * 3600));
        self::assertTrue(AiPriceCatalogueService::isDue($this->repository->state, $this->now + AiPriceCatalogueService::RETRY_AFTER_MISSING_ENDPOINT_SECONDS + 1));
        $price = $this->table(false)->find('anthropic', 'claude-sonnet-5-5');
        self::assertSame([AiPrice::ORIGIN_BUNDLED, true], [$price?->origin, $price?->complete], 'The bundled list still enforces budgets.');
    }

    #[Test]
    public function aMalformedOrUntrustworthyCatalogueIsRefusedAsAWhole(): void
    {
        $valid = $this->payload(5);
        $cases = [
            'invalid_contract schema' => array_replace_recursive($valid, ['catalogue' => ['schema' => 'aqg.ai-price-catalogue.v2']]),
            'invalid_contract currency' => array_replace_recursive($valid, ['catalogue' => ['currency' => 'EUR']]),
            'invalid_contract unit' => array_replace_recursive($valid, ['catalogue' => ['unit' => 'usd_per_1k_tokens']]),
            'invalid_contract version' => array_replace_recursive($valid, ['catalogue' => ['version' => '5']]),
            'invalid_rates zero' => $this->withModel($valid, 0, ['rates' => ['output' => 0]]),
            'invalid_rates float' => $this->withModel($valid, 0, ['rates' => ['input' => 0.75]]),
            'invalid_rates cached above input' => $this->withModel($valid, 0, ['rates' => ['cachedInput' => 9_000_000]]),
            'invalid_rates missing' => $this->withModel($valid, 0, ['rates' => ['cacheWrite' => null]]),
            'invalid_rates tier below standard' => $this->withModel($valid, 0, ['longContext' => ['threshold' => 1000, 'rates' => ['input' => 1, 'output' => 1, 'cachedInput' => 1, 'cacheWrite' => 1, 'cacheWriteExtended' => 1]]]),
            'invalid_model provider' => $this->withModel($valid, 0, ['provider' => 'mistral']),
            'invalid_model future verification' => $this->withModel($valid, 0, ['verifiedAt' => gmdate('c', $this->now + 3 * 86400)]),
            'invalid_model relative time' => $this->withModel($valid, 0, ['verifiedAt' => 'now']),
            'invalid_model time without zone' => $this->withModel($valid, 0, ['verifiedAt' => '2026-11-02 13:42:03']),
            'invalid_contract impossible date' => array_replace_recursive($valid, ['catalogue' => ['publishedAt' => '2026-02-31T10:00:00.000Z']]),
            'invalid_model insecure source' => $this->withModel($valid, 0, ['sourceUrl' => 'http://example.test/pricing']),
            'no_supported_model' => ['catalogue' => ['models' => [$valid['catalogue']['models'][array_key_last($valid['catalogue']['models'])]]] + $valid['catalogue']] + $valid,
        ];
        foreach ($cases as $case => $payload) {
            $this->repository = new InMemoryAiPriceCatalogueRepository();
            $this->responses = [new Response(200, ['Content-Type' => 'application/json', 'ETag' => '"x"'], (string)json_encode($payload))];
            self::assertSame(AiPriceCatalogueService::RESULT_FAILED, $this->service()->refreshIfDue($this->now), $case);
            self::assertSame(explode(' ', $case)[0], $this->repository->state['error_reason'], $case);
            self::assertSame([], $this->repository->prices, $case);
        }
    }

    #[Test]
    public function anOlderCatalogueOrAnEarlierVerifiedPriceNeverReplacesANewerOne(): void
    {
        $this->responses = [$this->catalogue(7, ['gpt-5.4-mini' => 800_000], $this->now - 1)];
        $service = $this->service();
        $service->refreshIfDue($this->now);

        $this->responses = [$this->catalogue(6, ['gpt-5.4-mini' => 700_000])];
        self::assertSame(AiPriceCatalogueService::RESULT_FAILED, $service->refreshIfDue($this->now + AiPriceCatalogueService::REFRESH_AFTER_SECONDS + 1));
        self::assertSame('version_regressed', $this->repository->state['error_reason']);

        // A newer catalogue whose entry for one model was verified before the price held: that price stays.
        $this->responses = [$this->catalogue(8, ['gpt-5.4-mini' => 600_000], $this->now - 2)];
        self::assertSame(AiPriceCatalogueService::RESULT_UPDATED, $service->refreshIfDue($this->now + AiPriceCatalogueService::REFRESH_AFTER_SECONDS + AiPriceCatalogueService::RETRY_AFTER_FAILURE_SECONDS + 2));
        self::assertSame(800_000, $this->table(false)->find('openai', 'gpt-5.4-mini')?->rates->input);
        self::assertSame(1, $this->repository->prices["openai\0gpt-5.4-mini"]['listed']);
    }

    #[Test]
    public function onlyOneRequestRefreshesAtATime(): void
    {
        $token = $this->repository->claim($this->now, AiPriceCatalogueService::LEASE_SECONDS);
        self::assertNotNull($token);

        self::assertSame(AiPriceCatalogueService::RESULT_BUSY, $this->service()->refreshIfDue($this->now + 1));
        self::assertSame([], $this->requests, 'The waiting request does not fetch; it prices with the copy it has.');

        $this->repository->releaseClaim((string)$token);
        $this->responses = [$this->catalogue(2)];
        self::assertSame(AiPriceCatalogueService::RESULT_UPDATED, $this->service()->refreshIfDue($this->now + 2));
    }

    #[Test]
    public function aModelTheCatalogueDropsKeepsItsPriceUnlisted(): void
    {
        $this->responses = [$this->catalogue(3)];
        $service = $this->service();
        $service->refreshIfDue($this->now);
        $payload = $this->payload(4);
        $payload['catalogue']['models'] = array_values(array_filter($payload['catalogue']['models'], static fn (array $model): bool => $model['model'] !== 'gpt-5.4-nano'));
        $this->responses = [new Response(200, ['Content-Type' => 'application/json', 'ETag' => '"aqg-ai-prices-4"'], (string)json_encode($payload))];

        $service->refreshIfDue($this->now + AiPriceCatalogueService::REFRESH_AFTER_SECONDS + 1);

        self::assertSame(0, $this->repository->prices["openai\0gpt-5.4-nano"]['listed']);
        self::assertSame(200_000, $this->table(false)->find('openai', 'gpt-5.4-nano')?->rates->input, 'Never zero.');
    }

    private function table(bool $withService = true): AiPriceTable
    {
        return new AiPriceTable($this->createMock(AiPriceRepositoryInterface::class), new AiModelCompatibilityRegistry(), $this->repository, $withService ? $this->service() : null);
    }

    private function service(): AiPriceCatalogueService
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(function (string $url, string $method, array $options): Response {
            $this->requests[] = ['url' => $url, 'headers' => $options['headers'] ?? []];
            $next = array_shift($this->responses) ?? new Response(500);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });

        return new AiPriceCatalogueService($factory, $this->repository, new AiModelCompatibilityRegistry());
    }

    /** @param array<string,int> $inputOverrides model => input rate */
    private function catalogue(int $version, array $inputOverrides = [], ?int $verifiedAt = null): Response
    {
        return new Response(200, ['Content-Type' => 'application/json', 'ETag' => '"aqg-ai-prices-' . $version . '"'], (string)json_encode($this->payload($version, $inputOverrides, $verifiedAt)));
    }

    /**
     * The AQG service's catalogue of 2 Nov 2026 in contract aqg.ai-price-catalogue.v1, plus a model this extension does
     * not support.
     *
     * @param array<string,int> $inputOverrides
     * @return array<string,mixed>
     */
    private function payload(int $version, array $inputOverrides = [], ?int $verifiedAt = null): array
    {
        $verified = gmdate('Y-m-d\TH:i:s.000\Z', $verifiedAt ?? $this->now);
        $models = [];
        foreach (['openai', 'anthropic'] as $provider) {
            foreach (AiBundledPriceCatalogue::modelIds($provider) as $id) {
                $bundled = AiBundledPriceCatalogue::find($provider, $id);
                $rates = self::contractRates($bundled->rates->toArray());
                if (isset($inputOverrides[$id])) {
                    $rates['input'] = $rates['cacheWrite'] = $rates['cacheWriteExtended'] = $inputOverrides[$id];
                }
                $models[] = [
                    'provider' => $provider, 'model' => $id, 'label' => $id, 'rates' => $rates,
                    'longContext' => $bundled->longContext ? ['threshold' => $bundled->longContext->threshold, 'rates' => self::contractRates($bundled->longContext->rates->toArray())] : null,
                    'verifiedAt' => $verified, 'listed' => true, 'sourceUrl' => $bundled->sourceUrl,
                ];
            }
        }
        $models[] = ['provider' => 'openai', 'model' => 'gpt-4o', 'label' => 'gpt-4o', 'rates' => ['input' => 2_500_000, 'output' => 10_000_000, 'cachedInput' => 1_250_000, 'cacheWrite' => 2_500_000, 'cacheWriteExtended' => 2_500_000], 'longContext' => null, 'verifiedAt' => $verified, 'listed' => true, 'sourceUrl' => 'https://developers.openai.com/api/docs/pricing.md'];

        return ['success' => true, 'catalogue' => [
            'schema' => 'aqg.ai-price-catalogue.v1', 'currency' => 'USD', 'unit' => 'micro_usd_per_1m_tokens',
            'version' => $version, 'publishedAt' => $verified,
            'providers' => [['id' => 'anthropic', 'sourceUrl' => 'https://platform.claude.com/docs/en/about-claude/pricing.md', 'lastVerifiedAt' => $verified], ['id' => 'openai', 'sourceUrl' => 'https://developers.openai.com/api/docs/pricing.md', 'lastVerifiedAt' => $verified]],
            'models' => $models,
        ]];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function withModel(array $payload, int $index, array $changes): array
    {
        $payload['catalogue']['models'][$index] = array_replace_recursive($payload['catalogue']['models'][$index], $changes);

        return $payload;
    }

    /**
     * @param array<string,int> $rates
     * @return array<string,int>
     */
    private static function contractRates(array $rates): array
    {
        return ['input' => $rates['input'], 'output' => $rates['output'], 'cachedInput' => $rates['cached_input'], 'cacheWrite' => $rates['cache_write'], 'cacheWriteExtended' => $rates['cache_write_extended']];
    }
}
