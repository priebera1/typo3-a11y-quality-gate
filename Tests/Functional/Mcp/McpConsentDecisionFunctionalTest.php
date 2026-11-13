<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\McpConsentController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * CG-2 (Fable gate): the TYPO3 consent decision with the real controller, user, permissions, ledger and route stack,
 * against a scripted Gateway. The decide route is POST-only behind TYPO3's login and needs its own route token (CSRF);
 * a user without `mcp.connect` can only refuse; Deny decides nothing locally; Allow grants only what the page offered
 * (narrowing, never widening); an expired request decides nothing; a repeated or concurrent submission of one request
 * reaches the Gateway once; and a decision the Gateway did not confirm (error, timeout, lost answer) leaves no consent
 * record behind, so a token minted anyway can never run (consent_unknown).
 */
final class McpConsentDecisionFunctionalTest extends AbstractFunctionalTestCase
{
    private const SECRET = 'aqgc_' . 'A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8S9t0U1v';
    private const PAIRING = '11111111-2222-4333-8444-555555555555';
    private const REQUEST_REF = 'aqgr_' . 'consentReferenceOfTheAuthorizationRequest01';
    private const ROUTE = 'web_a11y.mcpConsentDecide';
    private const REDIRECT = 'http://127.0.0.1:33418/callback?code=aqgo_code&state=s';

    /** @var list<array{path: string, body: array<string, mixed>}> */
    private array $requests = [];
    /** @var array<string, \Closure(array<string, mixed>): array{0: int, 1: array<string, mixed>}> */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv(McpHostKey::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
        $this->requests = [];
        $this->routes = [];
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request, array $options) {
            $path = $request->getUri()->getPath();
            $body = json_decode((string)$request->getBody(), true) ?: [];
            $this->requests[] = ['path' => $path, 'body' => $body];
            foreach ($this->routes as $pattern => $answer) {
                if (preg_match($pattern, $path) === 1) {
                    [$status, $json] = $answer($body);
                    if ($status === 0) {
                        // The request left, no answer came back (timeout, reset): the Gateway may or may not have decided.
                        return Create::rejectionFor(new ConnectException('Operation timed out', $request));
                    }
                    return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($json)));
                }
            }
            return Create::promiseFor(new Response(404, ['Content-Type' => 'application/json'], '{"success":false,"error":{"code":"route_not_found"}}'));
        });

        $pool = $this->get(ConnectionPool::class);
        foreach ([[1, 0, 'Client A', 1], [2, 1, 'Client A page', 0], [100, 0, 'Client B', 1]] as [$uid, $pid, $title, $isRoot]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'is_siteroot' => $isRoot,
                'perms_userid' => 0, 'perms_groupid' => 0, 'perms_user' => 0, 'perms_group' => 0, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $connect = "options.a11y_quality_gate.mcp.connect = 1\n";
        foreach ([[10, '1', $connect], [11, '1', $connect . "options.a11y_quality_gate.showScanNow = 0\n"], [12, '1', '']] as [$uid, $mounts, $tsConfig]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'pid' => 0, 'title' => 'Group ' . $uid, 'groupMods' => 'web,web_a11y', 'tables_select' => 'pages,tt_content',
                'tables_modify' => 'pages,tt_content', 'db_mountpoints' => $mounts, 'TSconfig' => $tsConfig, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[2, 'editor', 10], [3, 'reader', 11], [4, 'no-agents', 12], [5, 'second-editor', 10]] as [$uid, $username, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => $username, 'admin' => 0, 'usergroup' => (string)$group, 'options' => 3, 'workspace_perms' => 1, 'disable' => 0, 'deleted' => 0,
            ]);
        }
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite('client-a', 1, 'https://a.test/');
        $siteWriter->createNewBasicSite('client-b', 100, 'https://b.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $settings = $this->get(McpSiteSettingsRepository::class);
        $settings->save('client-a', true, ['findings:read', 'history:read', 'scans:run-page'], 1);
        $settings->save('client-b', true, ['findings:read', 'history:read', 'scans:run-page'], 1);

        $store = $this->get(McpConnectionStore::class);
        self::assertTrue($store->startPairing(self::PAIRING, 'ABCD2345', 'aqgp_poll', time() + 600, 'https://app.example/app/agent-access', 'admin'));
        self::assertTrue($store->completePairingIfCurrent(self::PAIRING, self::SECRET, 'slug', 'https://gw.example/mcp/i/slug'));
        self::assertTrue($store->markActivatedIfCurrent($store->get()['credentialId']));

        $this->routes['#/mcp/connector/authorization-requests/describe$#'] = fn (): array => [200, ['success' => true, 'request' => [
            'status' => 'pending', 'clientName' => 'Agent (self-declared)', 'clientId' => 'aqgmc_client', 'redirectHost' => '127.0.0.1',
            'redirectsToLoopback' => true, 'requestedScopes' => ['findings:read', 'history:read', 'scans:run-page'],
        ]]];
        $this->routes['#/mcp/connector/authorization-requests/decide$#'] = fn (): array => [200, ['success' => true, 'redirect' => self::REDIRECT, 'status' => 'decided']];
        $this->routes['#/mcp/connector/tokens$#'] = fn (): array => [200, ['success' => true, 'tokens' => []]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'], $GLOBALS['TYPO3_REQUEST']);
        putenv(McpHostKey::ENVIRONMENT_VARIABLE);
        @unlink($this->get(McpHostKey::class)->path());
        parent::tearDown();
    }

    private function as(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /** @param array<string, mixed> $body */
    private function decide(array $body): ResponseInterface
    {
        $router = $this->get(Router::class);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/mcp/consent/decide', 'POST'))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($router->getRoute(self::ROUTE)->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(McpConsentController::class)->decideAction($request);
    }

    /** @return list<array<string, mixed>> */
    private function decisions(): array
    {
        return array_values(array_map(
            static fn (array $request): array => $request['body'],
            array_filter($this->requests, static fn (array $request): bool => str_ends_with($request['path'], '/authorization-requests/decide'))
        ));
    }

    private function recorded(int $beUserUid, array $scopes, array $sites): bool
    {
        return $this->get(McpConsentLedger::class)->isRecorded(McpConsentLedger::consentRefHash(self::REQUEST_REF), $beUserUid, $scopes, $sites);
    }

    private function ledgerRows(): int
    {
        return (int)$this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_CONSENT)->count('*', Tables::MCP_CONSENT, []);
    }

    /** @return list<array{result: string, reason: string}> */
    private function audit(): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(Tables::MCP_AUDIT)
            ->select(['result', 'reason'], Tables::MCP_AUDIT, ['action' => 'consent'])->fetchAllAssociative();
    }

    #[Test]
    public function theDecideRouteIsPostOnlyBehindLoginAndNeedsItsOwnRouteToken(): void
    {
        $this->as(2);
        $router = $this->get(Router::class);
        $registered = $router->getRoute(self::ROUTE);
        self::assertIsObject($registered);
        self::assertSame(['POST'], $registered->getMethods());
        self::assertNotSame('public', $registered->getOption('access'), 'only signed-in backend users');
        $formProtection = $this->get(FormProtectionFactory::class)->createForType('backend');
        $request = (new ServerRequest('https://typo3-testing.local' . $registered->getPath(), 'POST'))
            ->withParsedBody(['request' => self::REQUEST_REF, 'decision' => 'allow', 'scopes' => ['findings:read'], 'sites' => ['client-a']])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($registered->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        try {
            $this->get(RouteDispatcher::class)->dispatch($request);
            self::fail('a decision without a route token (a cross-site POST) must be refused');
        } catch (MissingRequestTokenException) {
        }
        try {
            $this->get(RouteDispatcher::class)->dispatch($request->withParsedBody((array)$request->getParsedBody() + [
                'token' => $formProtection->generateToken('route', 'web_a11y.mcpMyAgents'),
            ]));
            self::fail('the token of another route must be refused');
        } catch (InvalidRequestTokenException) {
        }
        self::assertSame([], $this->decisions(), 'nothing reached the Gateway');
        self::assertSame(0, $this->ledgerRows());
    }

    #[Test]
    public function denyDecidesNothingLocallyAndReturnsTheAgentsRedirect(): void
    {
        $this->as(2);
        $response = $this->decide(['request' => self::REQUEST_REF, 'decision' => 'deny', 'scopes' => ['findings:read'], 'sites' => ['client-a']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([['request' => self::REQUEST_REF, 'decision' => 'deny']], $this->decisions(), 'no scopes, sites or user are sent with a denial');
        self::assertSame(0, $this->ledgerRows());
        self::assertStringContainsString('127.0.0.1', (string)$response->getBody());
        self::assertSame([['result' => 'denied', 'reason' => 'user_denied']], $this->audit());
    }

    #[Test]
    public function allowGrantsOnlyWhatThePageOfferedNeverWhatTheFormAdds(): void
    {
        // The reader may not run scans (showScanNow = 0): scans:run-page is not grantable for them, whatever is ticked.
        $this->as(3);
        $this->decide([
            'request' => self::REQUEST_REF, 'decision' => 'allow', 'duration' => '365',
            'scopes' => ['findings:read', 'scans:run-page', 'admin:all'], 'sites' => ['client-a', 'client-b', 'does-not-exist'],
        ]);

        $decisions = $this->decisions();
        self::assertCount(1, $decisions);
        self::assertSame('allow', $decisions[0]['decision']);
        self::assertSame(3, $decisions[0]['beUserUid']);
        self::assertSame(['findings:read'], $decisions[0]['scopes']);
        self::assertSame(['client-a'], $decisions[0]['siteIdentifiers'], 'client-b is outside the reader\'s mounts');
        self::assertSame(7, $decisions[0]['durationDays'], 'an unknown duration falls back to the shortest, 7 days (P1-5)');
        self::assertNull($decisions[0]['replaceTokenId']);
        self::assertTrue($this->recorded(3, ['findings:read'], ['client-a']));
        self::assertFalse($this->recorded(3, ['findings:read', 'scans:run-page'], ['client-a']), 'the record is the narrowed grant');
        self::assertSame([['result' => 'token_issued', 'reason' => '']], $this->audit());
    }

    #[Test]
    public function nothingSelectedOrAUserWhoMayNotConnectDecidesNoAllow(): void
    {
        $this->as(2);
        $this->decide(['request' => self::REQUEST_REF, 'decision' => 'allow', 'scopes' => ['admin:all'], 'sites' => ['client-a']]);
        self::assertSame([], $this->decisions(), 'nothing grantable was ticked: the page is shown again');
        self::assertSame(0, $this->ledgerRows());

        // U-07: without mcp.connect the request is answered like Deny, whatever the form sent.
        $this->as(4);
        $this->decide(['request' => self::REQUEST_REF, 'decision' => 'allow', 'scopes' => ['findings:read'], 'sites' => ['client-a']]);
        self::assertSame([['request' => self::REQUEST_REF, 'decision' => 'deny']], $this->decisions());
        self::assertSame(0, $this->ledgerRows());
    }

    #[Test]
    public function anExpiredOrAnsweredRequestDecidesNothing(): void
    {
        $this->as(2);
        $this->routes['#/mcp/connector/authorization-requests/describe$#'] = fn (): array => [200, ['success' => true, 'request' => ['status' => 'expired']]];
        $this->decide(['request' => self::REQUEST_REF, 'decision' => 'allow', 'scopes' => ['findings:read'], 'sites' => ['client-a']]);
        self::assertSame([], $this->decisions());

        // Pending when shown, expired by the time it is decided: the Gateway's 410 withdraws the local record.
        $this->routes['#/mcp/connector/authorization-requests/describe$#'] = fn (): array => [200, ['success' => true, 'request' => [
            'status' => 'pending', 'clientName' => 'Agent', 'clientId' => 'c', 'requestedScopes' => ['findings:read'],
        ]]];
        $this->routes['#/mcp/connector/authorization-requests/decide$#'] = fn (): array => [410, ['success' => false, 'error' => ['code' => 'request_expired']]];
        $this->decide(['request' => self::REQUEST_REF, 'decision' => 'allow', 'scopes' => ['findings:read'], 'sites' => ['client-a']]);
        self::assertCount(1, $this->decisions());
        self::assertFalse($this->recorded(2, ['findings:read'], ['client-a']));
        self::assertSame(0, $this->ledgerRows());
    }

    #[Test]
    public function aRepeatedOrConcurrentSubmissionIsDecidedOnce(): void
    {
        // The Gateway decides a request once (pending → decided under its row lock); every later decision is 410. The
        // describe answer stays "pending" here: both submissions loaded the page before either decided (concurrent).
        $decided = 0;
        $this->routes['#/mcp/connector/authorization-requests/decide$#'] = function () use (&$decided): array {
            return ++$decided === 1
                ? [200, ['success' => true, 'redirect' => self::REDIRECT, 'status' => 'decided']]
                : [410, ['success' => false, 'error' => ['code' => 'request_expired']]];
        };
        $this->as(2);
        $allow = ['request' => self::REQUEST_REF, 'decision' => 'allow', 'duration' => '30', 'scopes' => ['findings:read'], 'sites' => ['client-a']];
        $this->decide($allow);
        // Double click / a second tab with a wider selection: the ledger already holds this user's claim.
        $this->decide(['scopes' => ['findings:read', 'history:read']] + $allow);
        self::assertCount(1, $this->decisions(), 'the same user\'s second submission never reaches the Gateway');
        // Another user who obtained the same reference: their claim is their own, and the Gateway refuses it.
        $this->as(5);
        $this->decide($allow);

        self::assertCount(2, $this->decisions());
        self::assertSame(2, $decided, 'the Gateway was asked twice and accepted only the first');
        self::assertTrue($this->recorded(2, ['findings:read'], ['client-a']));
        self::assertFalse($this->recorded(2, ['findings:read', 'history:read'], ['client-a']), 'the second tab neither replaced nor widened the grant');
        self::assertFalse($this->recorded(5, ['findings:read'], ['client-a']), 'the refused user\'s claim was withdrawn');
        self::assertSame(1, $this->ledgerRows());
    }

    #[Test]
    public function aMissingDurationIsSevenDays(): void
    {
        // Public hardening (Fable P1-5): the shortest lifetime is the default and the fallback; longer is a choice.
        $this->as(2);
        $this->decide(['request' => self::REQUEST_REF, 'decision' => 'allow', 'scopes' => ['findings:read'], 'sites' => ['client-a']]);
        self::assertSame(7, $this->decisions()[0]['durationDays'] ?? null);
        $this->get(McpConsentLedger::class)->withdraw(self::REQUEST_REF, 2);
        $this->requests = [];
        $this->decide(['request' => self::REQUEST_REF, 'decision' => 'allow', 'duration' => '30', 'scopes' => ['findings:read'], 'sites' => ['client-a']]);
        self::assertSame(30, $this->decisions()[0]['durationDays'] ?? null);
    }

    #[Test]
    public function theConsentPagePreselectsReadingOnlyForSevenDaysAndItsDefaultPostGrantsNothingMore(): void
    {
        // Fable §9.1 #8: the agent asked for scans too; nothing but reading is ticked, and 7 days is selected.
        $this->as(2);
        $router = $this->get(Router::class);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/mcp/consent?request=' . self::REQUEST_REF, 'GET'))
            ->withQueryParams(['request' => self::REQUEST_REF])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($router->getRoute('web_a11y.mcpConsent')->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $html = (string)$this->get(McpConsentController::class)->showAction($request)->getBody();

        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $xpath = new \DOMXPath($document);
        $checked = static fn (string $name): array => array_map(
            static fn (\DOMElement $input): string => $input->getAttribute('value'),
            iterator_to_array($xpath->query('//input[@name="' . $name . '" and @checked]') ?: [])
        );
        $offered = static fn (string $name): array => array_map(
            static fn (\DOMElement $input): string => $input->getAttribute('value'),
            iterator_to_array($xpath->query('//input[@name="' . $name . '"]') ?: [])
        );
        self::assertSame(['findings:read', 'history:read', 'scans:run-page'], $offered('scopes[]'), 'every requested capability is shown');
        self::assertSame(['findings:read', 'history:read'], $checked('scopes[]'), 'only reading is ticked in advance');
        self::assertSame(['client-a'], $checked('sites[]'), 'the one site this user can see is ticked; several would not be');
        $selected = $xpath->query('//select[@name="duration"]/option[@selected]');
        self::assertSame('7', $selected !== false && $selected->length === 1 ? $selected->item(0)?->getAttribute('value') : null);

        // Submitting the page as it was shown — the form's default POST.
        $this->decide(['request' => self::REQUEST_REF, 'decision' => 'allow', 'duration' => '7', 'scopes' => $checked('scopes[]'), 'sites' => $checked('sites[]')]);
        $decision = $this->decisions()[0] ?? [];
        self::assertSame(['findings:read', 'history:read'], $decision['scopes'] ?? null);
        self::assertSame(['client-a'], $decision['siteIdentifiers'] ?? null);
        self::assertSame(7, $decision['durationDays'] ?? null);
    }

    #[Test]
    public function aDecisionTheGatewayDidNotConfirmLeavesNoConsentBehind(): void
    {
        $this->as(2);
        $allow = ['request' => self::REQUEST_REF, 'decision' => 'allow', 'scopes' => ['findings:read'], 'sites' => ['client-a']];
        foreach ([
            'Gateway error' => fn (): array => [503, ['success' => false, 'error' => ['code' => 'concurrent_update']]],
            'proxy error page instead of an answer' => fn (): array => [502, ['raw' => '<html>Bad gateway</html>']],
            'timeout after the request left (the Gateway may have decided)' => fn (): array => [0, []],
        ] as $case => $answer) {
            $this->routes['#/mcp/connector/authorization-requests/decide$#'] = $answer;
            $response = $this->decide($allow);
            self::assertSame(200, $response->getStatusCode(), $case);
            self::assertFalse($this->recorded(2, ['findings:read'], ['client-a']), $case . ': withdrawn, so a token minted anyway is refused as consent_unknown');
            self::assertSame(0, $this->ledgerRows(), $case);
        }
        // After an uncertain outcome the user can try again with the same request; it is decided once, now confirmed.
        $this->routes['#/mcp/connector/authorization-requests/decide$#'] = fn (): array => [200, ['success' => true, 'redirect' => self::REDIRECT]];
        $this->decide($allow);
        self::assertTrue($this->recorded(2, ['findings:read'], ['client-a']));
        self::assertCount(4, $this->decisions());
    }
}
