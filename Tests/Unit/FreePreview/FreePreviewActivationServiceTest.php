<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationStore;
use Priebera\A11yQualityGate\FreePreview\FreePreviewStatement;
use Priebera\A11yQualityGate\FreePreview\FreeTermsAcceptance;
use Priebera\A11yQualityGate\FreePreview\FreeTermsDocuments;
use Priebera\A11yQualityGate\FreePreview\PublicSiteAddressClassifier;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Registry;

/**
 * FREE Remote Preview activation against a scripted AQG API: only the service's confirmation of exactly what was sent
 * activates FREE, with the service's own UTC timestamp; an unticked declaration, a failed or forged answer, another
 * installation, another domain or newer terms never do. Switching FREE off keeps the acceptance for re-use.
 */
final class FreePreviewActivationServiceTest extends TestCase
{
    private const SITE = 'https://www.aqg-client.de/';
    private const SERVER_TIME = '2026-10-20T10:15:30.000Z';

    /** @var list<array{url:string, method:string, payload:array<string,mixed>|null}> */
    private array $requests = [];
    /** @var array<string, \Closure(array<string,mixed>|null): array{0:int,1:array<string,mixed>}> */
    private array $routes = [];
    /** @var array<string, mixed> */
    private array $registry = [];
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];
    private string $installationId = 'installation-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->routes['GET /free-preview/terms'] = fn (): array => [200, self::termsPayload('free-2026-11')];
        $this->routes['POST /free-preview/acceptances'] = fn (?array $request): array => [201, self::confirmation($request)];
    }

    #[Test]
    public function freeIsNotActivatedByDefault(): void
    {
        $service = $this->service();

        self::assertSame(FreePreviewActivationService::STATE_NOT_ACTIVATED, $service->status(self::SITE)['state']);
        self::assertSame('', $service->activeAcceptanceId(self::SITE));
    }

    #[Test]
    public function anUntickedDeclarationRecordsNothingAndSendsNothing(): void
    {
        $result = $this->service()->accept(self::SITE, 'main', $this->input(['authorityConfirmed' => false]), '2.1.0');

        self::assertSame('confirmation_required', $result['code']);
        self::assertSame([], $this->requests);
        self::assertSame([], $this->registry);
    }

    #[Test]
    public function anAcceptanceSendsTheIdentityDomainAndVersionsAndKeepsTheServersTimestamp(): void
    {
        $service = $this->service();
        $result = $service->accept(self::SITE, 'main', $this->input(['language' => 'de']), '2.1.0', 2_000_000_000);

        self::assertSame('accepted', $result['code']);
        $sent = $this->lastRequest('POST /free-preview/acceptances');
        self::assertSame('installation-1', $sent['installationId']);
        self::assertSame('https://www.aqg-client.de/', $sent['siteUrl']);
        self::assertSame('main', $sent['siteIdentifier']);
        self::assertSame((new DomainNormalizer())->normalizeFromSiteBase(self::SITE), $sent['domain']);
        self::assertSame('free-2026-11', $sent['termsVersion']);
        self::assertSame(['free_terms', 'dpa'], array_column($sent['documents'], 'type'));
        self::assertSame(['de', 'de'], array_column($sent['documents'], 'language'));
        self::assertSame(['language' => 'de', 'text' => FreePreviewStatement::text('de'), 'sha256' => FreePreviewStatement::sha256('de')], array_intersect_key($sent['statement'], array_flip(['language', 'text', 'sha256'])));
        self::assertSame(['name' => 'Example GmbH'], $sent['organisation']);
        self::assertSame(['name' => 'Ada Admin', 'email' => 'ada@client.example', 'typo3UserId' => 7, 'role' => 'typo3_administrator', 'authorityConfirmed' => true], $sent['acceptedBy']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sent['idempotencyKey']);

        // The client clock (2033) is only informational; the stored acceptance time is the service's.
        self::assertSame('2033-05-18T03:33:20Z', $sent['clientTimestamp']);
        $acceptance = $service->status(self::SITE)['acceptance'];
        self::assertInstanceOf(FreeTermsAcceptance::class, $acceptance);
        self::assertSame('2026-10-20T10:15:30Z', $acceptance->acceptedAt);
        self::assertSame(1_792_491_330, $acceptance->acceptedAtTimestamp);
        self::assertSame('acc_01JABCDEF', $service->activeAcceptanceId(self::SITE));
        self::assertSame(FreePreviewActivationService::STATE_ACTIVE, $service->status(self::SITE)['state']);
    }

    #[Test]
    public function theSameAcceptanceSubmittedAgainUsesTheSameIdempotencyKey(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0', 1_800_000_000);
        $first = $this->lastRequest('POST /free-preview/acceptances')['idempotencyKey'];
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0', 1_800_000_500);

        self::assertSame($first, $this->lastRequest('POST /free-preview/acceptances')['idempotencyKey']);
        $service->accept(self::SITE, 'main', $this->input(['organisation' => 'Other Ltd']), '2.1.0');
        self::assertNotSame($first, $this->lastRequest('POST /free-preview/acceptances')['idempotencyKey'], 'another legal customer is another acceptance');
    }

    /**
     * @return iterable<string, array{\Closure(array<string,mixed>): array<string,mixed>}>
     */
    public static function forgedConfirmations(): iterable
    {
        yield 'another installation' => [static fn (array $c): array => ['installationId' => 'installation-2'] + $c];
        yield 'another domain' => [static fn (array $c): array => ['domain' => 'evil.example'] + $c];
        yield 'another terms version' => [static fn (array $c): array => ['termsVersion' => 'free-2020-01'] + $c];
        yield 'not valid' => [static fn (array $c): array => ['status' => 'pending'] + $c];
        yield 'no server timestamp' => [static fn (array $c): array => array_diff_key($c, ['acceptedAt' => true])];
        yield 'timestamp without time zone' => [static fn (array $c): array => ['acceptedAt' => '2026-10-20 10:15:30'] + $c];
        yield 'no acceptance id' => [static fn (array $c): array => ['id' => ''] + $c];
    }

    /** @param \Closure(array<string,mixed>): array<string,mixed> $forge */
    #[Test]
    #[DataProvider('forgedConfirmations')]
    public function aConfirmationThatDoesNotConfirmWhatWasSentActivatesNothing(\Closure $forge): void
    {
        $this->routes['POST /free-preview/acceptances'] = static function (?array $request) use ($forge): array {
            $confirmation = self::confirmation($request);
            $confirmation['acceptance'] = $forge($confirmation['acceptance']);

            return [201, $confirmation];
        };
        $service = $this->service();

        self::assertSame('invalid_confirmation', $service->accept(self::SITE, 'main', $this->input(), '2.1.0')['code']);
        self::assertSame(FreePreviewActivationService::STATE_NOT_ACTIVATED, $service->status(self::SITE)['state']);
        self::assertSame([], $this->registry);
    }

    /**
     * @return iterable<string, array{string, int, array<string,mixed>, string}>
     */
    public static function failures(): iterable
    {
        yield 'terms not published' => ['GET /free-preview/terms', 404, ['success' => false, 'error' => ['code' => 'route_not_found']], 'terms_not_published'];
        yield 'terms service down' => ['GET /free-preview/terms', 503, ['success' => false, 'error' => ['code' => 'service_unavailable']], 'unavailable'];
        yield 'acceptance endpoint missing' => ['POST /free-preview/acceptances', 404, ['success' => false], 'not_available'];
        yield 'acceptance service down' => ['POST /free-preview/acceptances', 502, ['success' => false, 'error' => ['code' => 'bad_gateway']], 'unavailable'];
        yield 'terms changed meanwhile' => ['POST /free-preview/acceptances', 409, ['success' => false, 'error' => ['code' => 'free_terms_outdated']], 'terms_changed'];
        yield 'installation proof failed' => ['POST /free-preview/acceptances', 403, ['success' => false, 'error' => ['code' => 'invalid_installation_proof']], 'proof_failed'];
        yield 'rate limited' => ['POST /free-preview/acceptances', 429, ['success' => false, 'error' => ['code' => 'rate_limit_exceeded']], 'rate_limited'];
        yield 'statement not accepted' => ['POST /free-preview/acceptances', 409, ['success' => false, 'error' => ['code' => 'free_terms_statement_mismatch']], 'statement_mismatch'];
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('failures')]
    public function aFailedRequestNeverActivatesFree(string $route, int $status, array $body, string $expectedCode): void
    {
        $this->routes[$route] = static fn (): array => [$status, $body];
        $service = $this->service();

        self::assertSame($expectedCode, $service->accept(self::SITE, 'main', $this->input(), '2.1.0')['code']);
        self::assertSame('', $service->activeAcceptanceId(self::SITE));
        self::assertSame([], $this->registry);
    }

    #[Test]
    public function aNetworkFailureNeverActivatesFree(): void
    {
        $this->routes['POST /free-preview/acceptances'] = static function (): array {
            throw new class ('timeout') extends \RuntimeException implements ClientExceptionInterface {};
        };
        $service = $this->service();

        self::assertSame('unavailable', $service->accept(self::SITE, 'main', $this->input(), '2.1.0')['code']);
        self::assertSame([], $this->registry);
    }

    #[Test]
    public function termsThatChangedAfterTheyWereShownAreNotAccepted(): void
    {
        $result = $this->service()->accept(self::SITE, 'main', $this->input(['termsVersion' => 'free-2026-01']), '2.1.0');

        self::assertSame('terms_changed', $result['code']);
        self::assertSame([], $this->requestsTo('POST /free-preview/acceptances'));
    }

    #[Test]
    public function aStatementTheServiceDidNotPublishIsNotSent(): void
    {
        $this->routes['GET /free-preview/terms'] = static function (): array {
            $payload = self::termsPayload('free-2026-11');
            $payload['terms']['statements'] = ['en' => ['sha256' => str_repeat('a', 64)]];

            return [200, $payload];
        };

        self::assertSame('statement_mismatch', $this->service()->accept(self::SITE, 'main', $this->input(), '2.1.0')['code']);
        self::assertSame([], $this->requestsTo('POST /free-preview/acceptances'));
    }

    #[Test]
    public function aLocalSiteCannotBeActivated(): void
    {
        self::assertSame('site_not_public', $this->service()->accept('https://client.ddev.site/', 'main', $this->input(), '2.1.0')['code']);
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function newTermsRequireANewAcceptance(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');
        $newTerms = FreeTermsDocuments::fromApi(self::termsPayload('free-2027-03'));

        self::assertSame(FreePreviewActivationService::STATE_RENEWAL_REQUIRED, $service->status(self::SITE, $newTerms)['state']);
        self::assertSame(FreePreviewActivationService::STATE_RENEWAL_REQUIRED, $service->setEnabled(self::SITE, true, $newTerms));
    }

    #[Test]
    public function switchingOffStopsNewScansAndSwitchingOnReusesTheAcceptance(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');
        $requests = count($this->requests);

        self::assertSame('disabled', $service->setEnabled(self::SITE, false));
        self::assertSame(FreePreviewActivationService::STATE_DISABLED, $service->status(self::SITE)['state']);
        self::assertSame('', $service->activeAcceptanceId(self::SITE));

        self::assertSame('enabled', $service->setEnabled(self::SITE, true));
        self::assertSame('acc_01JABCDEF', $service->activeAcceptanceId(self::SITE));
        self::assertCount($requests, $this->requests, 'switching does not create a new contractual record');
    }

    #[Test]
    public function anotherInstallationDoesNotInheritTheAcceptance(): void
    {
        $this->service()->accept(self::SITE, 'main', $this->input(), '2.1.0');
        $this->installationId = 'installation-copy';
        $service = $this->service();

        self::assertSame(FreePreviewActivationService::STATE_IDENTITY_CHANGED, $service->status(self::SITE)['state']);
        self::assertSame('', $service->activeAcceptanceId(self::SITE));
        self::assertSame('not_activated', $service->setEnabled(self::SITE, true));
    }

    #[Test]
    public function anotherDomainIsNotActivated(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');

        self::assertSame(FreePreviewActivationService::STATE_NOT_ACTIVATED, $service->status('https://www.other-aqg-client.de/')['state']);
    }

    #[Test]
    public function anExpiredAcceptanceIsNotActive(): void
    {
        $this->routes['POST /free-preview/acceptances'] = static function (?array $request): array {
            $confirmation = self::confirmation($request);
            $confirmation['acceptance']['validUntil'] = '2027-01-01T00:00:00Z';

            return [201, $confirmation];
        };
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');

        self::assertSame(FreePreviewActivationService::STATE_ACTIVE, $service->status(self::SITE, null, 1_790_000_000)['state']);
        self::assertSame(FreePreviewActivationService::STATE_EXPIRED, $service->status(self::SITE, null, 1_800_000_000)['state']);
    }

    #[Test]
    public function theStatusCheckOnlyChangesStateOnTheServicesAnswerForThisAcceptance(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');

        $this->routes['POST /free-preview/acceptances/status'] = static fn (): array => [503, ['success' => false]];
        self::assertSame('unavailable', $service->refresh(self::SITE, 'main'));
        self::assertSame(FreePreviewActivationService::STATE_ACTIVE, $service->status(self::SITE)['state']);

        $this->routes['POST /free-preview/acceptances/status'] = static fn (): array => [200, ['success' => true, 'acceptance' => ['id' => 'acc_OTHER', 'status' => 'revoked']]];
        self::assertSame('invalid_confirmation', $service->refresh(self::SITE, 'main'));
        self::assertSame(FreePreviewActivationService::STATE_ACTIVE, $service->status(self::SITE)['state']);

        $this->routes['POST /free-preview/acceptances/status'] = static fn (): array => [200, ['success' => true, 'acceptance' => ['id' => 'acc_01JABCDEF', 'status' => 'valid'], 'currentTermsVersion' => 'free-2027-03']];
        self::assertSame('outdated', $service->refresh(self::SITE, 'main'));
        self::assertSame(FreePreviewActivationService::STATE_RENEWAL_REQUIRED, $service->status(self::SITE)['state']);

        $this->routes['POST /free-preview/acceptances/status'] = static fn (): array => [200, ['success' => true, 'acceptance' => ['id' => 'acc_01JABCDEF', 'status' => 'revoked']]];
        self::assertSame('revoked', $service->refresh(self::SITE, 'main'));
        self::assertSame(FreePreviewActivationService::STATE_REVOKED, $service->status(self::SITE)['state']);
        self::assertSame('installation-1', $this->lastRequest('POST /free-preview/acceptances/status')['installationId']);
    }

    #[Test]
    public function aRefusalByTheServiceIsRecorded(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');

        $service->recordServiceRefusal(self::SITE, 'free_terms_outdated');
        self::assertSame(FreePreviewActivationService::STATE_RENEWAL_REQUIRED, $service->status(self::SITE)['state']);
        $service->recordServiceRefusal(self::SITE, 'some_other_error');
        self::assertSame(FreePreviewActivationService::STATE_RENEWAL_REQUIRED, $service->status(self::SITE)['state']);
    }

    #[Test]
    public function aStatusAnswerWithoutValidUntilKeepsTheKnownExpiry(): void
    {
        $this->routes['POST /free-preview/acceptances'] = static function (?array $request): array {
            $confirmation = self::confirmation($request);
            $confirmation['acceptance']['validUntil'] = '2027-01-01T00:00:00Z';

            return [201, $confirmation];
        };
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');
        $this->routes['POST /free-preview/acceptances/status'] = static fn (): array => [200, ['success' => true, 'acceptance' => ['id' => 'acc_01JABCDEF', 'status' => 'valid']]];

        $service->refresh(self::SITE, 'main', 1_790_000_000);

        self::assertSame(FreePreviewActivationService::STATE_EXPIRED, $service->status(self::SITE, null, 1_800_000_000)['state']);
    }

    #[Test]
    public function aRequestWithoutTheAcceptanceDoesNotTurnRenewalIntoRevoked(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');
        $service->recordServiceRefusal(self::SITE, 'free_terms_outdated');

        $service->recordServiceRefusal(self::SITE, 'free_terms_acceptance_required');

        self::assertSame(FreePreviewActivationService::STATE_RENEWAL_REQUIRED, $service->status(self::SITE)['state']);
    }

    #[Test]
    public function unpublishedTermsAreNotRequestedOnEveryView(): void
    {
        $this->routes['GET /free-preview/terms'] = static fn (): array => [404, ['success' => false, 'error' => ['code' => 'route_not_found']]];
        $service = $this->service();

        self::assertSame('not_published', $service->getTerms('en')['error']);
        self::assertSame('not_published', $service->getTerms('en')['error']);
        self::assertCount(1, $this->requestsTo('GET /free-preview/terms'));
        $service->getTerms('en', true);
        self::assertCount(2, $this->requestsTo('GET /free-preview/terms'), 'an acceptance always asks again');
    }

    #[Test]
    public function aCorrectedNameIsANewSubmissionNotAConflictingReplay(): void
    {
        $service = $this->service();
        $service->accept(self::SITE, 'main', $this->input(), '2.1.0');
        $first = $this->lastRequest('POST /free-preview/acceptances')['idempotencyKey'];
        $service->accept(self::SITE, 'main', $this->input(['name' => 'Ada Admin-Lovelace']), '2.1.0');

        self::assertNotSame($first, $this->lastRequest('POST /free-preview/acceptances')['idempotencyKey']);
    }

    #[Test]
    public function onlyCompleteHttpsTermsArePublishedTerms(): void
    {
        self::assertNotNull(FreeTermsDocuments::fromApi(self::termsPayload('free-2026-11')));
        $payload = self::termsPayload('free-2026-11');
        foreach ($payload['terms']['documents'] as &$document) {
            $document['url'] = str_replace('https://', 'http://', $document['url']);
        }
        unset($document);
        self::assertNull(FreeTermsDocuments::fromApi($payload), 'documents without https are not offered');

        $payload = self::termsPayload('free-2026-11');
        $payload['terms']['documents'] = array_values(array_filter($payload['terms']['documents'], static fn (array $d): bool => $d['type'] !== 'dpa'));
        self::assertNull(FreeTermsDocuments::fromApi($payload), 'terms without a DPA are not terms');
        self::assertNull(FreeTermsDocuments::fromApi(['success' => false]));
    }

    /** @param array<string, mixed> $overrides */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'organisation' => 'Example GmbH',
            'name' => 'Ada Admin',
            'email' => 'Ada@Client.example',
            'authorityConfirmed' => true,
            'termsVersion' => 'free-2026-11',
            'language' => 'en',
            'userUid' => 7,
        ];
    }

    /** @return array<string, mixed> */
    private static function termsPayload(string $version): array
    {
        $documents = [];
        foreach (['free_terms', 'dpa'] as $type) {
            foreach (['en', 'de'] as $language) {
                $documents[] = [
                    'type' => $type,
                    'language' => $language,
                    'title' => strtoupper($type) . ' ' . $language,
                    'version' => '1.0',
                    'url' => 'https://aqg.example/legal/' . $type . '-' . $language . '-1.0.pdf',
                    'sha256' => hash('sha256', $type . $language),
                ];
            }
        }

        return ['success' => true, 'terms' => [
            'version' => $version,
            'effectiveAt' => '2026-11-01T00:00:00Z',
            'documents' => $documents,
            'statements' => [
                'en' => ['sha256' => FreePreviewStatement::sha256('en')],
                'de' => ['sha256' => FreePreviewStatement::sha256('de')],
            ],
        ]];
    }

    /** @param array<string, mixed>|null $request @return array<string, mixed> */
    private static function confirmation(?array $request): array
    {
        return ['success' => true, 'acceptance' => [
            'id' => 'acc_01JABCDEF',
            'status' => 'valid',
            'termsVersion' => $request['termsVersion'] ?? '',
            'installationId' => $request['installationId'] ?? '',
            'domain' => $request['domain'] ?? '',
            'acceptedAt' => self::SERVER_TIME,
            'validUntil' => null,
            'replayed' => false,
        ]];
    }

    /** @return array<string, mixed> */
    private function lastRequest(string $route): array
    {
        $requests = $this->requestsTo($route);
        self::assertNotSame([], $requests, $route . ' was not requested');

        return (array)end($requests)['payload'];
    }

    /** @return list<array{url:string, method:string, payload:array<string,mixed>|null}> */
    private function requestsTo(string $route): array
    {
        [$method, $path] = explode(' ', $route, 2);

        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === $method && str_contains(parse_url($request['url'], PHP_URL_PATH) ?: '', $path)
                && str_ends_with(parse_url($request['url'], PHP_URL_PATH) ?: '', $path)
        ));
    }

    private function service(): FreePreviewActivationService
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(function (string $url, string $method, array $options): ResponseInterface {
            $payload = isset($options['body']) ? json_decode((string)$options['body'], true, 512, JSON_THROW_ON_ERROR) : null;
            $this->requests[] = ['url' => $url, 'method' => $method, 'payload' => $payload];
            $route = $method . ' ' . (parse_url($url, PHP_URL_PATH) ?: '');
            foreach ($this->routes as $pattern => $handler) {
                if (str_ends_with($route, substr($pattern, strpos($pattern, ' ') + 1)) && str_starts_with($route, explode(' ', $pattern)[0] . ' ')) {
                    [$status, $body] = $handler($payload);

                    return $this->response($status, $body);
                }
            }

            return $this->response(404, ['success' => false]);
        });

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(fn (string $namespace, string $key, mixed $default = null): mixed => $this->registry[$key] ?? $default);
        $registry->method('set')->willReturnCallback(function (string $namespace, string $key, mixed $value): void {
            $this->registry[$key] = $value;
        });
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturnCallback(fn (): string => $this->installationId);
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getDisplayPayload')->willReturnCallback(fn (string $key): ?array => $this->cache[$key] ?? null);
        $cache->method('setDisplayPayload')->willReturnCallback(function (string $key, array $payload): void {
            $this->cache[$key] = $payload;
        });

        return new FreePreviewActivationService(
            new AqgApiClient($factory),
            new FreePreviewActivationStore($registry),
            $identity,
            new DomainNormalizer(),
            $cache,
            new PublicSiteAddressClassifier(),
        );
    }

    /** @param array<string, mixed> $body */
    private function response(int $status, array $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturn('');

        return $response;
    }
}
