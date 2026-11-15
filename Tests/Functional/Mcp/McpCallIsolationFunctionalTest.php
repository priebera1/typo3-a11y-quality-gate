<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Media\MediaAiAvailability;
use Priebera\A11yQualityGate\Mcp\McpCallScopedState;
use Priebera\A11yQualityGate\Mcp\McpDeferredWork;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemPresenter;
use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Release-gate finding 1, regression cases R1–R6 of the contract (R7 is Tests/Unit/Mcp/McpCallScopedStateGuardTest):
 * one test process is one long-running connector worker — one container, one instance of every singleton — that runs
 * calls of different users and contexts one after another. No authorization, AI readiness, budget or request decided
 * for one call may decide the next.
 */
final class McpCallIsolationFunctionalTest extends AbstractFunctionalTestCase
{
    use McpRemediationFixture;

    private const TOKEN = 'abcdef01-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemediationFixture();
    }

    /** Editor A prepares an item for finding a0 on page 2 (no provider request: generate=false). */
    private function itemOfEditorA(): int
    {
        $this->setUpBackendUser(self::EDITOR_A);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('client-a');
        $result = $this->suggestionTools(new McpDeferredWork())->prepare($GLOBALS['BE_USER'], $site, ['findingIds' => [$this->issues['a0']], 'generate' => false], true);
        $itemUid = (int)($result['results'][0]['item']['itemId'] ?? 0);
        self::assertGreaterThan(0, $itemUid, json_encode($result));
        unset($GLOBALS['BE_USER']);

        return $itemUid;
    }

    /** @return array<string, mixed> */
    private function principal(int $beUserUid, array $sites = ['client-a']): array
    {
        return ['tokenId' => self::TOKEN, 'tokenSuffix' => 'abcd', 'clientName' => 'Agent', 'beUserUid' => $beUserUid, 'beUserName' => 'x',
            'scopes' => self::SCOPES, 'siteIdentifiers' => $sites, 'expiresAt' => '2099-01-01T00:00:00Z', 'consentRefHash' => str_repeat('a', 64)];
    }

    /** @return array<string, mixed> */
    private function suggestionStatus(int $beUserUid, int $itemUid): array
    {
        return $this->get(McpToolExecutor::class)->execute('aqg_get_suggestion_status', ['siteIdentifier' => 'client-a', 'itemIds' => [$itemUid]], $this->principal($beUserUid));
    }

    /** The page verdict the container's RemediationAccessService gives inside a call of this user (same worker). */
    private function containerVerdict(int $beUserUid, int $pageUid): bool
    {
        return $this->get(McpUserContext::class)->runAs($beUserUid, fn (): bool => $this->get(RemediationAccessService::class)->canReadPage($pageUid));
    }

    #[Test]
    public function theContainerResetsEveryMemoServiceOfTheContractBeforeAndAfterEachCall(): void
    {
        $tagged = (new \ReflectionProperty(McpUserContext::class, 'callScopedState'))->getValue($this->get(McpUserContext::class));
        $instances = [];
        foreach ($tagged as $service) {
            self::assertInstanceOf(McpCallScopedState::class, $service);
            $instances[$service::class] = $service;
        }
        foreach ([RemediationAccessService::class, EditorAiAccessService::class, AiPriceTable::class, RuleConfigurationService::class, IssueLocationService::class,
            RemediationItemPresenter::class, MediaAiAvailability::class, LocalFindingSourceAccessService::class, RemoteResultAuthorizationService::class] as $class) {
            self::assertArrayHasKey($class, $instances, $class . ' is not reset per MCP call.');
            self::assertSame($this->get($class), $instances[$class], $class . ': the reset must reach the shared instance the tools use.');
        }
    }

    #[Test]
    public function r1AnotherUserInTheSameWorkerNeverInheritsAPageVerdict(): void
    {
        $itemUid = $this->itemOfEditorA();

        // User A reads page 2 and their item (memo warmed in the worker's RemediationAccessService).
        self::assertTrue($this->containerVerdict(self::EDITOR_A, self::PAGE));
        self::assertTrue($this->suggestionStatus(self::EDITOR_A, $itemUid)['entries'][0]['found']);

        // User B: same scopes, same web mount, queue permission — but no page permission on page 2.
        self::assertFalse($this->containerVerdict(self::EDITOR_B, self::PAGE), 'R1: user B inherited user A\'s page verdict.');
        self::assertSame(['itemId' => $itemUid, 'found' => false], $this->suggestionStatus(self::EDITOR_B, $itemUid)['entries'][0]);
        // And A is unaffected afterwards.
        self::assertTrue($this->suggestionStatus(self::EDITOR_A, $itemUid)['entries'][0]['found']);
    }

    #[Test]
    public function r2APagePermissionRemovedBetweenCallsIsSeenByTheNextCall(): void
    {
        $itemUid = $this->itemOfEditorA();
        self::assertTrue($this->containerVerdict(self::EDITOR_A, self::PAGE));
        self::assertTrue($this->suggestionStatus(self::EDITOR_A, $itemUid)['entries'][0]['found']);

        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['perms_userid' => self::ADMIN], ['uid' => self::PAGE]);

        self::assertFalse($this->containerVerdict(self::EDITOR_A, self::PAGE), 'R2: the removed page permission was not seen.');
        self::assertFalse($this->suggestionStatus(self::EDITOR_A, $itemUid)['entries'][0]['found']);
    }

    #[Test]
    public function r3ALostWebMountIsSeenByTheNextCall(): void
    {
        $itemUid = $this->itemOfEditorA();
        self::assertTrue($this->containerVerdict(self::EDITOR_A, self::PAGE));
        self::assertTrue($this->suggestionStatus(self::EDITOR_A, $itemUid)['entries'][0]['found']);

        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['db_mountpoints' => '100'], ['uid' => 11]);

        self::assertFalse($this->containerVerdict(self::EDITOR_A, self::PAGE), 'R3: the lost web mount was not seen.');
        try {
            $entry = $this->suggestionStatus(self::EDITOR_A, $itemUid)['entries'][0];
            self::assertFalse($entry['found']);
        } catch (\Priebera\A11yQualityGate\Mcp\McpCallRefused $refused) {
            // Equally acceptable: without a mount in client-a the site itself is no longer available.
            self::assertSame('site_not_available', $refused->reason);
        }
    }

    #[Test]
    public function r4AReachedBudgetIsSeenByThePrepareGateOfTheNextCall(): void
    {
        $this->setUpBackendUser(self::ADMIN);
        $deferred = new McpDeferredWork();
        $tools = $this->suggestionTools($deferred);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('client-a');
        $userContext = $this->userContextResetting($this->access, $this->editorAiAccess);

        // Call 1: AI is ready (the readiness memo of EditorAiAccessService is warmed).
        $first = $userContext->runAs(self::ADMIN, fn ($user) => $tools->prepare($user, $site, ['findingIds' => [$this->issues['a0']], 'generate' => false], true));
        self::assertSame('queued', $first['results'][0]['outcome'], json_encode($first));

        // The site's monthly budget is reached between the calls.
        $this->resolveHook = static function (): void {
            throw new AiConfigurationException('Monthly AI budget reached.', AiBudgetService::CODE_BUDGET_REACHED);
        };
        $second = $userContext->runAs(self::ADMIN, fn ($user) => $tools->prepare($user, $site, ['findingIds' => [$this->issues['a1']]], true));
        self::assertSame(['skipped', EditorAiAccessService::STATE_BUDGET_REACHED], [$second['results'][0]['outcome'], $second['results'][0]['reason']], 'R4: the budget verdict was reused.');
        self::assertNull($second['results'][0]['item']);
        self::assertSame(0, $deferred->count(), 'Nothing is set to generate.');
        self::assertSame(1, $this->rowCount('tx_a11y_remediation_item'));
    }

    #[Test]
    public function r5ASecondSiteWithPausedAiIsRefusedAlthoughTheFirstWasReady(): void
    {
        $this->setUpBackendUser(self::ADMIN);
        $tools = $this->suggestionTools(new McpDeferredWork());
        $userContext = $this->userContextResetting($this->access, $this->editorAiAccess);
        $this->resolveHook = static function (string $site): void {
            if ($site === 'client-b') {
                throw new AiConfigurationException('AI is paused for this site.', 1771002001);
            }
        };

        $s1 = $userContext->runAs(self::ADMIN, fn ($user) => $tools->prepare($user, $this->get(SiteFinder::class)->getSiteByIdentifier('client-a'), ['findingIds' => [$this->issues['a0']], 'generate' => false], true));
        self::assertSame('queued', $s1['results'][0]['outcome']);
        $s2 = $userContext->runAs(self::ADMIN, fn ($user) => $tools->prepare($user, $this->get(SiteFinder::class)->getSiteByIdentifier('client-b'), ['findingIds' => [$this->issues['b0']], 'generate' => false], true));
        self::assertSame('skipped', $s2['results'][0]['outcome'], 'R5: client-b was prepared on client-a\'s readiness.');
        self::assertNotSame(EditorAiAccessService::STATE_READY, $s2['results'][0]['reason']);
        self::assertSame(1, $this->rowCount('tx_a11y_remediation_item'));
    }

    #[Test]
    public function r6ARequestObjectOfOneCallNeverReachesTheNextAndIsRestoredAfterIt(): void
    {
        $before = new ServerRequest('https://outer.invalid/');
        $GLOBALS['TYPO3_REQUEST'] = $before;
        $userContext = $this->get(McpUserContext::class);
        $ofA = null;
        // Call of user A leaves a backend request behind (as McpToolExecutor::backendRequest() does for scans).
        $userContext->runAs(self::ADMIN, function () use (&$ofA): void {
            self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS, 'A call starts without the caller\'s request.');
            $ofA = new ServerRequest('https://aqg-mcp-connector.invalid/typo3/ajax/a11y');
            $GLOBALS['TYPO3_REQUEST'] = $ofA;
        });
        self::assertSame($before, $GLOBALS['TYPO3_REQUEST'], 'After runAs() the request is what it was before.');

        $userContext->runAs(self::EDITOR_B, function () use ($ofA): void {
            self::assertNotSame($ofA, $GLOBALS['TYPO3_REQUEST'] ?? null, 'R6: user B\'s call saw user A\'s request.');
        });
        self::assertSame($before, $GLOBALS['TYPO3_REQUEST']);

        // Also when the call fails, and when the user cannot act at all.
        try {
            $userContext->runAs(self::ADMIN, static function (): void {
                $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://leak.invalid/');
                throw new \RuntimeException('tool failed');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($before, $GLOBALS['TYPO3_REQUEST']);
        try {
            $userContext->runAs(999, static fn () => null);
            self::fail('A missing user gets no context.');
        } catch (\Priebera\A11yQualityGate\Mcp\McpCallRefused $refused) {
            self::assertSame('user_not_active', $refused->reason);
        }
        self::assertSame($before, $GLOBALS['TYPO3_REQUEST']);
        unset($GLOBALS['TYPO3_REQUEST']);
        $userContext->runAs(self::ADMIN, static function (): void {
            $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://leak.invalid/');
        });
        self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS, 'No request before, none after.');
    }

    #[Test]
    public function aFailingResetStillRestoresUserAndRequest(): void
    {
        $failing = new class () implements McpCallScopedState {
            public bool $armed = false;
            public function resetCallState(): void
            {
                if ($this->armed) {
                    throw new \RuntimeException('reset failed');
                }
            }
        };
        $userContext = $this->userContextResetting($failing);
        $before = new ServerRequest('https://outer.invalid/');
        $GLOBALS['TYPO3_REQUEST'] = $before;
        $GLOBALS['BE_USER'] = $outerUser = new \stdClass();
        try {
            $userContext->runAs(self::ADMIN, static function () use ($failing): void {
                $failing->armed = true;
            });
            self::fail('The failing reset is reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame('reset failed', $exception->getMessage());
        }
        self::assertSame($before, $GLOBALS['TYPO3_REQUEST']);
        self::assertSame($outerUser, $GLOBALS['BE_USER']);
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['BE_USER']);
    }
}
