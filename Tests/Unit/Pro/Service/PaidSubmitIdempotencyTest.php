<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use Priebera\A11yQualityGate\Pro\Service\PaidSubmitIdempotencyStore;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProTokenService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter;
use TYPO3\CMS\Core\Registry;

/**
 * AQG 2.0.2 persona audit O1: a paid frontend submit that timed out may still have been accepted by the crawler. The
 * scan ran as an orphan (result never shown, Trial credit spent) and the retry got "a scan is already running". Every
 * paid submit now sends an Idempotency-Key, kept until the crawler answers, so the retry of the same request gets the
 * accepted scan back.
 */
final class PaidSubmitIdempotencyTest extends TestCase
{
    private const JOB = 'a0000000-0000-4000-8000-000000000001';

    /** @var array<string, mixed> */
    private array $registryEntries = [];

    /** @var list<string> */
    private array $sentKeys = [];

    #[Test]
    public function aTimedOutSubmitIsRepeatedWithTheSameKeyAndPicksUpTheAcceptedScan(): void
    {
        $answers = [new ApiRequestFailedException('AQG crawler request failed: Operation timed out after 10001 milliseconds', 0), self::JOB];
        $service = $this->service($answers);

        try {
            $this->submit($service);
            self::fail('a submit without an answer is reported');
        } catch (TokenRefreshException $exception) {
            $presented = (new RemoteScanErrorPresenter())->present($exception);
            self::assertSame('submit_unconfirmed', $presented['code']);
            self::assertSame(503, $presented['status']);
            self::assertSame('Scan start not confirmed', $presented['title']);
            self::assertStringContainsString('instead of starting a second one', $presented['message']);
            self::assertStringNotContainsString('timed out', $presented['message']);
        }

        self::assertSame(self::JOB, $this->submit($service)->jobId);
        self::assertCount(2, $this->sentKeys);
        self::assertSame($this->sentKeys[0], $this->sentKeys[1], 'the retry carries the key of the unanswered submit');
        self::assertMatchesRegularExpression('/^aqg-paid-[a-f0-9]{32}$/', $this->sentKeys[0]);
    }

    #[Test]
    public function anAnsweredSubmitReleasesItsKeySoTheNextScanIsANewOne(): void
    {
        $service = $this->service([self::JOB, self::JOB]);

        $this->submit($service);
        $this->submit($service);

        self::assertNotSame($this->sentKeys[0], $this->sentKeys[1]);
        self::assertSame([], $this->registryEntries, 'nothing is kept once the crawler answered');
    }

    /**
     * @return iterable<string, array{ApiRequestFailedException}>
     */
    public static function definitiveRefusalProvider(): iterable
    {
        yield 'scan already running' => [new ApiRequestFailedException('busy', 429, null, 'too_many_active_jobs')];
        yield 'trial limit' => [new ApiRequestFailedException('limit', 429, null, 'trial_crawl_limit')];
        yield 'target refused' => [new ApiRequestFailedException('refused', 400, null, 'private_network_blocked')];
        yield 'never sent in this request' => [new ApiRequestFailedException('skipped', 0, null, 'transport_error')];
    }

    #[Test]
    #[DataProvider('definitiveRefusalProvider')]
    public function aDefinitiveRefusalReleasesTheKeyAndKeepsItsOwnReason(ApiRequestFailedException $refusal): void
    {
        $service = $this->service([$refusal, self::JOB]);

        try {
            $this->submit($service);
            self::fail('the refusal is reported');
        } catch (TokenRefreshException $exception) {
            self::assertNotSame('submit_unconfirmed', (new RemoteScanErrorPresenter())->present($exception)['code']);
        }
        $this->submit($service);

        self::assertNotSame($this->sentKeys[0], $this->sentKeys[1]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unconfirmedStatusProvider(): iterable
    {
        yield 'gateway timeout' => [504];
        yield 'server error' => [500];
        yield 'request timeout' => [408];
    }

    #[Test]
    #[DataProvider('unconfirmedStatusProvider')]
    public function aServerErrorMayHaveAcceptedTheScan(int $status): void
    {
        $service = $this->service([new ApiRequestFailedException('error', $status, null, 'internal_error'), self::JOB]);

        try {
            $this->submit($service);
        } catch (TokenRefreshException) {
        }
        $this->submit($service);

        self::assertSame($this->sentKeys[0], $this->sentKeys[1]);
    }

    /**
     * An answer that never reached the crawler's replay lookup (its global rate limit, a token the crawler refused and
     * a licence API that then did not answer) says nothing about the run an earlier unconfirmed attempt may have
     * started: the key stays, so the next attempt still gets that run instead of a second scan.
     */
    #[Test]
    #[DataProvider('definitiveRefusalProvider')]
    public function aRefusalAfterAnUnconfirmedAttemptKeepsItsKey(ApiRequestFailedException $refusal): void
    {
        $service = $this->service([new ApiRequestFailedException('timeout', 0), $refusal, self::JOB]);

        foreach ([1, 2] as $attempt) {
            try {
                $this->submit($service);
                self::fail('attempt ' . $attempt . ' is reported');
            } catch (TokenRefreshException) {
            }
        }
        self::assertSame(self::JOB, $this->submit($service)->jobId);

        self::assertCount(3, $this->sentKeys);
        self::assertSame($this->sentKeys[0], $this->sentKeys[2], 'the accepted run of the first attempt is picked up');
        self::assertSame([], $this->registryEntries, 'the accepted answer releases the key');
    }

    #[Test]
    public function aTokenRefreshTheLicenceApiDoesNotAnswerKeepsTheKeyOfAnUnconfirmedAttempt(): void
    {
        $answers = [new ApiRequestFailedException('timeout', 0), new ApiRequestFailedException('revoked', 401, null, 'token_revoked'), self::JOB];
        $refreshes = 0;
        $service = $this->service($answers, function (string $domain, string $version, bool $forceRefresh = false) use (&$refreshes): AccessTokenResult {
            if ($forceRefresh && ++$refreshes === 1) {
                throw new TokenRefreshException('AQG API did not answer the token request.');
            }

            return new AccessTokenResult('jwt', 3600, time(), 'pro', ['crawler']);
        });

        foreach ([1, 2] as $attempt) {
            try {
                $this->submit($service);
                self::fail('attempt ' . $attempt . ' is reported');
            } catch (TokenRefreshException) {
            }
        }
        $this->submit($service);

        self::assertSame($this->sentKeys[0], $this->sentKeys[2]);
    }

    /**
     * Verify fix scans the page as it is now: it never picks up a page scan that an unconfirmed "Scan this page" may
     * have started before the fix.
     */
    #[Test]
    public function aVerifyFixScanDoesNotReplayAnUnconfirmedScanOfThePage(): void
    {
        $service = $this->service([new ApiRequestFailedException('timeout', 0), self::JOB]);

        try {
            $this->submit($service);
        } catch (TokenRefreshException) {
        }
        $this->submit($service, purpose: 'verify_fix');

        self::assertNotSame($this->sentKeys[0], $this->sentKeys[1]);
    }

    #[Test]
    public function aChangedRequestIsANewScanWithItsOwnKey(): void
    {
        $service = $this->service([new ApiRequestFailedException('timeout', 0), self::JOB]);

        try {
            $this->submit($service);
        } catch (TokenRefreshException) {
        }
        $this->submit($service, maxPages: 50);

        self::assertNotSame($this->sentKeys[0], $this->sentKeys[1]);
    }

    #[Test]
    public function theTokenRefreshRetryOfOneSubmitUsesItsKey(): void
    {
        $service = $this->service([new ApiRequestFailedException('revoked', 401, null, 'token_revoked'), self::JOB]);

        self::assertSame(self::JOB, $this->submit($service)->jobId);
        self::assertCount(2, $this->sentKeys);
        self::assertSame($this->sentKeys[0], $this->sentKeys[1]);
    }

    #[Test]
    public function anOldUnansweredKeyIsNotReusedForALaterScan(): void
    {
        $registry = $this->registry();
        $store = new PaidSubmitIdempotencyStore($registry);
        $key = $store->keyFor('fingerprint');
        self::assertSame($key, $store->keyFor('fingerprint'));

        $entry = $this->registryEntries[array_key_first($this->registryEntries)];
        $this->registryEntries[array_key_first($this->registryEntries)] = ['key' => $entry['key'], 'createdAt' => time() - 901];
        self::assertNotSame($key, $store->keyFor('fingerprint'));

        $store->release('fingerprint', 'aqg-paid-' . str_repeat('0', 32));
        self::assertNotSame([], $this->registryEntries, 'only the key it issued releases an entry');
    }

    #[Test]
    public function aSecretIsNeverPartOfWhatIsStored(): void
    {
        $service = $this->service([new ApiRequestFailedException('timeout', 0)]);
        try {
            $this->submit($service, scannerToken: 'secret-scanner-token', httpAuthPass: 'secret-password');
        } catch (TokenRefreshException) {
        }

        $stored = json_encode($this->registryEntries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $stored);
    }

    /**
     * @param list<string|ApiRequestFailedException> $answers job id, or the failure of that attempt
     */
    private function service(array $answers, ?\Closure $getValidToken = null): ProCrawlerService
    {
        $client = $this->createMock(AqgCrawlerClient::class);
        $client->method('submit')->willReturnCallback(function (mixed ...$arguments) use (&$answers): CrawlerSubmitResponseDto {
            $this->sentKeys[] = (string)$arguments[18];
            $answer = array_shift($answers);
            if ($answer instanceof ApiRequestFailedException) {
                throw $answer;
            }

            return CrawlerSubmitResponseDto::fromArray(['jobId' => (string)$answer, 'status' => 'queued']);
        });

        $tokens = $this->createMock(ProTokenService::class);
        if ($getValidToken !== null) {
            $tokens->method('getValidToken')->willReturnCallback($getValidToken);
        } else {
            $tokens->method('getValidToken')->willReturn(new AccessTokenResult('jwt', 3600, time(), 'pro', ['crawler']));
        }
        $licence = $this->createMock(ProLicenceService::class);
        $licence->method('validate')->willReturn(new LicenceValidationResult(valid: true, plan: 'pro', features: ['crawler']));

        return new ProCrawlerService($licence, $tokens, $client, null, new PaidSubmitIdempotencyStore($this->registry()));
    }

    private function submit(ProCrawlerService $service, int $maxPages = 20, string $scannerToken = '', string $httpAuthPass = '', string $purpose = ''): \Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult
    {
        return $service->submit(
            'example.org', '2.0.2', 'main', 'https://example.org/', 'https://example.org/sitemap.xml', RemoteScanSourceType::Sitemap,
            $maxPages, false, 'en', false, true, $scannerToken, $httpAuthPass !== '' ? 'user' : '', $httpAuthPass,
            purpose: $purpose,
        );
    }

    private function registry(): Registry
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(fn (string $namespace, string $key, mixed $default = null): mixed => $this->registryEntries[$namespace . '/' . $key] ?? $default);
        $registry->method('set')->willReturnCallback(function (string $namespace, string $key, mixed $value): void {
            $this->registryEntries[$namespace . '/' . $key] = $value;
        });
        $registry->method('remove')->willReturnCallback(function (string $namespace, string $key): void {
            unset($this->registryEntries[$namespace . '/' . $key]);
        });

        return $registry;
    }
}
