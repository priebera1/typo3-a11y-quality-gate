<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\McpChangeApprovalController;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Mcp\Intelligence\McpIntelligenceTools;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpCallRefused;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Mcp\Remediation\McpApprovedChangeTools;
use Priebera\A11yQualityGate\Mcp\Remediation\McpChangeApprovalRepository;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RichTextRecordWriter;
use Priebera\A11yQualityGate\Tests\Functional\Remediation\AbstractDirectApplyFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Security\SudoMode\Exception\VerificationRequiredException;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * remediations:apply-approved (post-3.0.0): Discover → Propose → Preview → the person's approval in the TYPO3 backend →
 * Apply → Verify, through the real AI Fixes workflow, DataHandler and AQG's check of the saved record, and every way an
 * approval must not authorize a write: none, forged, foreign user/token/site, changed suggestion, changed record,
 * expired, reused, superseded, rejected, lost permission, language and workspace mismatch, concurrent applies, a failed
 * or unconfirmed save, and a save whose accessibility check still finds the problem.
 */
final class McpApprovedChangeFunctionalTest extends AbstractDirectApplyFunctionalTestCase
{
    private const TOKEN = '6f1c2a5e-0d3b-4c1a-9e7f-2b8d4c6a1e01';
    private const OTHER_TOKEN = '0a9b8c7d-6e5f-4a3b-8c1d-0e9f8a7b6c5d';
    /** A link whose aria-label the rule reads first: a text change saves but cannot fix it (still reported). */
    private const LABELLED = '<p>Read <a href="/contact" aria-label="click here">click here</a> today.</p>';
    private const BUTTON_FIXED = '<form action="/search"><input type="search" name="q" aria-label="Search terms"><button type="submit" class="icon-search" aria-label="Search"></button></form>';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['mcpApprovedChanges'] = '1';
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 16, 'pid' => 1, 'CType' => 'text', 'header' => 'Element 16', 'bodytext' => self::LABELLED, 'sys_language_uid' => 0, 'tstamp' => 1000,
        ]);
        $this->finding(16, 'rte.non_descriptive_link', self::LABELLED);
        // The editor may connect agents (the token's user); the approval page requires it like every agent call.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', [
            'TSconfig' => "options.a11y_quality_gate.ai.editor = 1\noptions.a11y_quality_gate.mcp.connect = 1",
        ], ['uid' => 1]);
    }

    #[Test]
    public function anApprovedChangeIsAppliedOnceReadBackVerifiedAndAudited(): void
    {
        [$item, $request] = $this->proposal(12, 'Search');
        self::assertSame('awaiting_approval', $request['outcome']);
        self::assertFalse($request['changed']);
        self::assertSame(self::BUTTON, $this->bodytext(12), 'requesting writes nothing');
        // The preview is the stored record, not the agent's claim: exact BEFORE and AFTER of the field.
        self::assertSame(self::BUTTON, $request['preview']['untrusted']['before']);
        self::assertSame(self::BUTTON_FIXED, $request['preview']['untrusted']['after']);
        self::assertSame(['kind' => 'rich_text', 'table' => 'tt_content', 'uid' => 12, 'field' => 'bodytext', 'pageUid' => 1, 'languageUid' => 0, 'workspaceId' => 0, 'recordHidden' => false], $request['preview']['target']);

        $premature = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('awaiting_approval', $premature['outcome'], 'no approval, no write');
        self::assertSame(self::BUTTON, $this->bodytext(12));

        $this->personApproves($request['approvalId']);
        // The connector worker runs for days: its request clock is the worker's start, not now (end-to-end finding).
        $GLOBALS['EXEC_TIME'] = $GLOBALS['SIM_EXEC_TIME'] = time() - 86400;
        $applied = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());

        self::assertSame('verified', $applied['outcome'], (string)$applied['reason']);
        self::assertGreaterThanOrEqual(time() - 60, (int)$this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->fetchOne('SELECT tstamp FROM tt_content WHERE uid = 12'), 'TYPO3 stamps the write with the time of the apply');
        self::assertTrue($applied['saved']);
        self::assertTrue($applied['accessibilityVerified']);
        self::assertSame('live', $applied['publication']);
        self::assertSame(self::BUTTON_FIXED, $this->bodytext(12), 'exactly the approved AFTER value');
        self::assertSame(RemediationStatus::VERIFIED, $this->item((int)$item['uid'])['status']);

        // Retry-safe: a repeat answers the stored result and writes nothing.
        $this->setBodytext(12, self::BUTTON_FIXED . '<!-- editor -->');
        $again = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('verified', $again['outcome']);
        self::assertSame('replayed', $again['call']);
        self::assertSame(self::BUTTON_FIXED . '<!-- editor -->', $this->bodytext(12), 'a replay never writes again');
        self::assertSame(1, $this->eventCount((int)$item['uid'], 'saved'));

        $audit = array_map(static fn (array $row): string => $row['action'] . ':' . $row['result'] . ':' . $row['source'], $this->get(McpAuditRepository::class)->findRecent(20));
        self::assertContains('change_approval:requested:connector', $audit);
        self::assertContains('change_apply:applied:connector', $audit);
        $row = $this->approvals()->findByRef($request['approvalId']);
        self::assertSame([McpChangeApprovalRepository::APPLIED, RemediationStatus::VERIFIED, self::EDITOR], [$row['status'], $row['result_status'], (int)$row['decided_by']]);
        self::assertStringNotContainsString('aria-label', json_encode($row), 'the approval row stores hashes, not content');
    }

    #[Test]
    public function theApprovalPageShowsTheStoredValuesAndOnlyItsUserDecidesBehindTheRouteToken(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $html = (string)$this->show($request['approvalId'])->getBody();
        self::assertStringContainsString('data-aqg-change="before">' . htmlspecialchars(self::BUTTON), $html);
        self::assertStringContainsString('data-aqg-change="after">' . htmlspecialchars(self::BUTTON_FIXED), $html);
        self::assertStringContainsString('Test agent', $html);
        // The change itself is shown at once: the element BEFORE and AFTER, with only the added attribute marked, and
        // what the new text is (U1-U3 of the readiness review).
        self::assertStringContainsString('data-aqg-change="element-before">…' . htmlspecialchars('<button type="submit" class="icon-search">') . '…</pre>', $html);
        self::assertMatchesRegularExpression('#data-aqg-change="element-after">…' . preg_quote(htmlspecialchars('<button type="submit" class="icon-search"'), '#') . '<ins><span class="visually-hidden">Added: </span>' . preg_quote(htmlspecialchars(' aria-label="Search"'), '#') . '</ins>&gt;…</pre>#u', $html);
        self::assertStringContainsString('New accessible name (aria-label):</span> <strong data-aqg-change="value">Search</strong>', $html);
        self::assertStringNotContainsString('<del>', $html, 'nothing is removed by this change');
        self::assertStringContainsString('formaction="', $html);

        // Another backend user sees nothing and cannot decide.
        $this->login(self::ADMIN);
        self::assertStringContainsString('does not exist or is not yours', (string)$this->show($request['approvalId'])->getBody());
        $this->decide(['approval' => $request['approvalId'], 'binding' => $this->approvals()->findByRef($request['approvalId'])['binding_hash'], 'decision' => 'approve']);
        self::assertSame(McpChangeApprovalRepository::PENDING, $this->approvals()->findByRef($request['approvalId'])['status']);

        // The decision route is POST-only, behind login, and needs its own route token.
        $this->login(self::EDITOR);
        $router = $this->get(Router::class);
        $route = $router->getRoute('web_a11y.mcpChangeApprovalDecide');
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame('aqg-mcp-change-approval', $route->getOption('sudoMode')['group'] ?? null, 'a decision needs the password again (sudo mode)');
        $post = (new ServerRequest('https://typo3-testing.local' . $route->getPath(), 'POST'))
            ->withParsedBody(['approval' => $request['approvalId'], 'binding' => $this->approvals()->findByRef($request['approvalId'])['binding_hash'], 'decision' => 'approve'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($route->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $post;
        try {
            $this->get(RouteDispatcher::class)->dispatch($post);
            self::fail('a cross-site POST without a route token must be refused');
        } catch (MissingRequestTokenException) {
        }
        try {
            $this->get(RouteDispatcher::class)->dispatch($post->withParsedBody((array)$post->getParsedBody() + [
                'token' => $this->get(FormProtectionFactory::class)->createForType('backend')->generateToken('route', 'web_a11y.mcpMyAgents'),
            ]));
            self::fail('the token of another route must be refused');
        } catch (InvalidRequestTokenException) {
        }
        self::assertSame(McpChangeApprovalRepository::PENDING, $this->approvals()->findByRef($request['approvalId'])['status']);

        // A forged binding (anything but what the page showed) approves nothing.
        $this->decide(['approval' => $request['approvalId'], 'binding' => str_repeat('0', 64), 'decision' => 'approve']);
        self::assertSame(McpChangeApprovalRepository::STALE, $this->approvals()->findByRef($request['approvalId'])['status']);
    }

    #[Test]
    public function theControllerApprovesExactlyWhatItShowedAndTheAgentApplies(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->show($request['approvalId']);
        $html = (string)$this->decide(['approval' => $request['approvalId'], 'binding' => $this->approvals()->findByRef($request['approvalId'])['binding_hash'], 'decision' => 'approve'])->getBody();
        self::assertStringContainsString('Approved', $html);
        self::assertSame(McpChangeApprovalRepository::APPROVED, $this->approvals()->findByRef($request['approvalId'])['status']);

        $applied = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('verified', $applied['outcome']);
        self::assertSame(self::BUTTON_FIXED, $this->bodytext(12));
    }

    #[Test]
    public function aNewRequestClosesAnInterruptedApplyThatNoTokenCanSettleAnyMore(): void
    {
        // End-to-end finding (TYPO3 14, host under load): the connector killed the worker mid-apply, then the person
        // revoked the token. AI Fixes gives the item back as not written; the old approval must not stay "applying".
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        $row = $this->approvals()->findByRef($request['approvalId']);
        self::assertTrue($this->approvals()->claim((int)$row['uid'], time() - McpChangeApprovalRepository::APPLY_INTERRUPTED_AFTER - 60));

        $again = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$row['item_uid']], $this->principal(), true);

        self::assertSame('awaiting_approval', $again['outcome'], (string)($again['reason'] ?? ''));
        $old = $this->approvals()->findByRef($request['approvalId']);
        self::assertSame([McpChangeApprovalRepository::FAILED, 'interrupted'], [$old['status'], $old['status_reason']]);
        self::assertSame(self::BUTTON, $this->bodytext(12), 'nothing was written');

        // A running apply (started within the window) is never closed by a new request.
        $this->personApproves($again['approvalId']);
        $running = $this->approvals()->findByRef($again['approvalId']);
        self::assertTrue($this->approvals()->claim((int)$running['uid'], time()));
        $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$row['item_uid']], $this->principal(), true);
        self::assertSame(McpChangeApprovalRepository::APPLYING, $this->approvals()->findByRef($again['approvalId'])['status']);
    }

    #[Test]
    public function rejectingNeedsNoPasswordAndAClosedRequestShowsItsStateInWords(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $route = $this->get(Router::class)->getRoute('web_a11y.mcpChangeApprovalReject');
        self::assertSame(['POST'], $route->getMethods());
        self::assertNull($route->getOption('sudoMode'), 'rejecting writes nothing: no password step-up');

        // Only the person's own request: another user's rejection changes nothing.
        $this->login(self::ADMIN);
        $this->reject(['approval' => $request['approvalId']]);
        self::assertSame(McpChangeApprovalRepository::PENDING, $this->approvals()->findByRef($request['approvalId'])['status']);

        $this->login(self::EDITOR);
        $html = (string)$this->reject(['approval' => $request['approvalId'], 'decision' => 'approve'])->getBody();
        self::assertStringContainsString('Rejected', $html, 'the reject route never approves, whatever is posted');
        self::assertSame(McpChangeApprovalRepository::REJECTED, $this->approvals()->findByRef($request['approvalId'])['status']);
        self::assertSame('approval_rejected', $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal())['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
        self::assertStringContainsString('Its state is: rejected.', (string)$this->show($request['approvalId'])->getBody());
    }

    #[Test]
    public function aRejectedApprovalWritesNothing(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->show($request['approvalId']);
        $this->decide(['approval' => $request['approvalId'], 'binding' => '', 'decision' => 'reject']);

        $answer = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('approval_rejected', $answer['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
    }

    #[Test]
    public function anApprovalNeverServesAnotherUserTokenSiteOrAForgedId(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        $refusals = [];
        foreach ([
            'forged id' => fn () => $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => bin2hex(random_bytes(16))], $this->principal()),
            'not an id' => fn () => $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => '../' . $request['approvalId']], $this->principal()),
            'other token' => fn () => $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal(self::OTHER_TOKEN)),
            'other user' => function () use ($request) {
                $this->login(self::ADMIN);
                return $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal(self::TOKEN, self::ADMIN));
            },
            'other site' => function () use ($request) {
                $this->login(self::EDITOR);
                GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_mcp_change_approval')
                    ->update('tx_a11y_mcp_change_approval', ['site_identifier' => 'elsewhere'], ['approval_ref' => $request['approvalId']]);
                return $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
            },
        ] as $case => $call) {
            try {
                $call();
                $refusals[$case] = 'answered';
            } catch (McpCallRefused $refused) {
                $refusals[$case] = $refused->reason;
            }
        }
        self::assertSame(array_fill_keys(['forged id', 'not an id', 'other token', 'other user', 'other site'], 'approval_not_found'), $refusals);
        self::assertSame(self::BUTTON, $this->bodytext(12));
    }

    #[Test]
    public function aTamperedApprovalRowIsStaleAndWritesNothing(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        // Even someone who could write the row cannot widen it: the binding is recomputed from the record and compared.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_mcp_change_approval')
            ->update('tx_a11y_mcp_change_approval', ['target_uid' => 10], ['approval_ref' => $request['approvalId']]);

        $answer = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('stale_content', $answer['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
        self::assertSame(self::CONTACT, $this->bodytext(10));
    }

    #[Test]
    public function aChangedSuggestionAfterApprovalIsStale(): void
    {
        [$item, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        // The reviewer edits the value in AI Fixes: a new proposal version, not the approved one.
        $fresh = $this->item((int)$item['uid']);
        $this->workflow()->editValue((int)$item['uid'], (int)$fresh['row_version'], 'Find products');

        $answer = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('stale_content', $answer['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
    }

    #[Test]
    public function aRecordChangedAfterThePreviewIsNeverOverwritten(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        $editor = self::BUTTON . '<p>Added by an editor after the approval.</p>';
        $this->setBodytext(12, $editor);

        $answer = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('stale_content', $answer['outcome']);
        self::assertFalse($answer['saved']);
        self::assertSame($editor, $this->bodytext(12), 'the newer editor change stays');
    }

    #[Test]
    public function aRecordChangedBetweenRequestAndApprovalCannotBeApproved(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->setBodytext(12, self::BUTTON . ' ');
        $html = (string)$this->show($request['approvalId'])->getBody();
        self::assertStringContainsString('out of date', $html);
        self::assertSame(McpChangeApprovalRepository::STALE, $this->approvals()->findByRef($request['approvalId'])['status']);
    }

    #[Test]
    public function expiredSupersededAndReusedApprovalsWriteNothing(): void
    {
        [, $first] = $this->proposal(12, 'Search');
        $second = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => $first['itemId']], $this->principal(), true);
        self::assertSame('approval_superseded', $this->tools()->status($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $first['approvalId']], $this->principal(), true)['outcome']);
        $row = $this->approvals()->findByRef($first['approvalId']);
        self::assertFalse($this->approvals()->decide((int)$row['uid'], self::EDITOR, (string)$row['binding_hash'], true, time()), 'a superseded request cannot be approved');

        $this->personApproves($second['approvalId']);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_mcp_change_approval')
            ->update('tx_a11y_mcp_change_approval', ['expires_at' => time() - 1], ['approval_ref' => $second['approvalId']]);
        $answer = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $second['approvalId']], $this->principal());
        self::assertSame('approval_expired', $answer['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
    }

    #[Test]
    public function lostTypo3PermissionsBetweenApprovalAndApplyWriteNothing(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['tables_modify' => ''], ['uid' => 1]);
        $this->login(self::EDITOR);

        $answer = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('permission_denied', $answer['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
    }

    #[Test]
    public function aUserWithoutEditRightsCannotEvenRequestAnApproval(): void
    {
        [$item] = $this->proposal(12, 'Search');
        $this->login(self::READER);
        try {
            $answer = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$item['uid']], $this->principal(self::TOKEN, self::READER), true);
            self::assertNotSame('awaiting_approval', $answer['outcome']);
        } catch (McpCallRefused $refused) {
            self::assertSame('item_not_available', $refused->reason);
        }
        self::assertSame(1, $this->rows('tx_a11y_mcp_change_approval'), 'only the editor\'s request exists');
    }

    #[Test]
    public function languageAndWorkspaceMismatchesAreRefused(): void
    {
        [$item, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        // The approval names language 0; the record now claims another language: the binding no longer matches.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_mcp_change_approval')
            ->update('tx_a11y_mcp_change_approval', ['language_uid' => 1], ['approval_ref' => $request['approvalId']]);
        self::assertSame('stale_content', $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal())['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));

        // A workspace item is never proposed for an agent apply.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item')->update('tx_a11y_remediation_item', ['workspace_id' => 1], ['uid' => (int)$item['uid']]);
        $GLOBALS['BE_USER']->workspace = 1;
        try {
            $answer = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$item['uid']], $this->principal(), true);
            self::assertSame('workspace_unsupported', $answer['reason']);
        } catch (McpCallRefused $refused) {
            self::assertSame('item_not_available', $refused->reason);
        }
        $GLOBALS['BE_USER']->workspace = 0;
    }

    #[Test]
    public function concurrentAppliesOfOneApprovalWriteOnce(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        $row = $this->approvals()->findByRef($request['approvalId']);
        // The first caller's claim; the second concurrent call loses the compare-and-set.
        self::assertTrue($this->approvals()->claim((int)$row['uid'], time()));
        self::assertFalse($this->approvals()->claim((int)$row['uid'], time()));

        $second = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('apply_in_progress', $second['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
    }

    #[Test]
    public function aFailedSaveIsReportedAsFailedWithNothingWritten(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        $failing = new class (GeneralUtility::makeInstance(ConnectionPool::class)) extends RichTextRecordWriter {
            public function write(string $table, int $uid, string $field, string $expected, string $value): string
            {
                return self::WRITE_FAILED;
            }
        };

        $answer = $this->tools($failing)->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('failed', $answer['outcome']);
        self::assertFalse($answer['saved']);
        self::assertSame(self::BUTTON, $this->bodytext(12));
    }

    #[Test]
    public function aSaveThatIsNotFoundInTheRecordIsNeverReportedAsSaved(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        $lying = new class (GeneralUtility::makeInstance(ConnectionPool::class)) extends RichTextRecordWriter {
            public function write(string $table, int $uid, string $field, string $expected, string $value): string
            {
                return self::SAVED;
            }
        };

        $answer = $this->tools($lying)->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('failed', $answer['outcome']);
        self::assertFalse($answer['saved']);
        self::assertFalse($answer['accessibilityVerified']);
    }

    #[Test]
    public function aSavedChangeThatAqgStillReportsIsAppliedButNotVerified(): void
    {
        [, $request] = $this->proposal(16, 'Contact and directions');
        $this->personApproves($request['approvalId']);

        $answer = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('applied_not_verified', $answer['outcome'], (string)$answer['reason']);
        self::assertTrue($answer['saved']);
        self::assertFalse($answer['accessibilityVerified']);
        self::assertStringContainsString('>Contact and directions</a>', $this->bodytext(16));
    }

    #[Test]
    public function withoutContentReadThePreviewCarriesNoContent(): void
    {
        [$item] = $this->proposal(12, 'Search');
        $request = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$item['uid']], $this->principal(), false);
        self::assertArrayNotHasKey('untrusted', $request['preview']);
        self::assertArrayNotHasKey('afterSha256', $request['preview'], 'no hash of content that cannot be read');
        self::assertSame('tt_content', $request['preview']['target']['table']);
    }

    #[Test]
    public function aDecisionWithAValidRouteTokenStillNeedsThePasswordAgain(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $router = $this->get(Router::class);
        $route = $router->getRoute('web_a11y.mcpChangeApprovalDecide');
        $post = (new ServerRequest('https://typo3-testing.local' . $route->getPath(), 'POST'))
            ->withParsedBody([
                'approval' => $request['approvalId'], 'binding' => $this->approvals()->findByRef($request['approvalId'])['binding_hash'], 'decision' => 'approve',
                'token' => $this->get(FormProtectionFactory::class)->createForType('backend')->generateToken('route', 'web_a11y.mcpChangeApprovalDecide'),
            ])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($route->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $post;
        try {
            $this->get(RouteDispatcher::class)->dispatch($post);
            self::fail('TYPO3 sudo mode must ask for the password before the decision runs');
        } catch (VerificationRequiredException) {
        }
        self::assertSame(McpChangeApprovalRepository::PENDING, $this->approvals()->findByRef($request['approvalId'])['status']);
    }

    #[Test]
    public function openingTheLinkRecordsNoReviewAndDecidingDoes(): void
    {
        [$item, $request] = $this->proposal(12, 'Search');
        $this->show($request['approvalId']);
        self::assertFalse($this->get(RemediationItemRepository::class)->hasSeen((int)$item['uid'], self::EDITOR, (int)$item['proposal_version']), 'a GET writes no review evidence');
        $this->decide(['approval' => $request['approvalId'], 'binding' => $this->approvals()->findByRef($request['approvalId'])['binding_hash'], 'decision' => 'approve']);
        self::assertTrue($this->get(RemediationItemRepository::class)->hasSeen((int)$item['uid'], self::EDITOR, (int)$item['proposal_version']));
    }

    #[Test]
    public function aRecordOpenInAnEditorDefersTheApplyWithoutLosingTheApproval(): void
    {
        [, $request] = $this->proposal(12, 'Search');
        $this->personApproves($request['approvalId']);
        $locks = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_lockedrecords');
        $locks->insert('sys_lockedrecords', ['userid' => 77, 'username' => 'colleague', 'tstamp' => time(), 'record_table' => 'tt_content', 'record_uid' => 12, 'record_pid' => 1]);

        $deferred = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());
        self::assertSame('awaiting_retry', $deferred['outcome']);
        self::assertSame(self::BUTTON, $this->bodytext(12));

        $locks->delete('sys_lockedrecords', ['record_uid' => 12]);
        self::assertSame('verified', $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal())['outcome']);
    }

    #[Test]
    public function aRecordWithAWorkspaceDraftIsNotChangedInLive(): void
    {
        [$item] = $this->proposal(12, 'Search');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 112, 'pid' => 1, 'CType' => 'text', 'bodytext' => self::BUTTON . '<p>draft</p>', 't3ver_oid' => 12, 't3ver_wsid' => 1, 't3ver_state' => 0,
        ]);
        $answer = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$item['uid']], $this->principal(), true);
        self::assertSame('workspace_version_exists', $answer['reason']);
    }

    #[Test]
    public function anAgentCannotFloodThePersonWithRequests(): void
    {
        [$item] = $this->proposal(12, 'Search');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_mcp_change_approval')->update('tx_a11y_mcp_change_approval', ['item_uid' => 9999], ['item_uid' => (int)$item['uid']]);
        for ($i = 0; $i < McpChangeApprovalRepository::MAX_OPEN_PER_TOKEN - 1; $i++) {
            $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$item['uid']], $this->principal(), true);
            GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_mcp_change_approval')->update('tx_a11y_mcp_change_approval', ['item_uid' => 9000 + $i], ['item_uid' => (int)$item['uid']]);
        }
        try {
            $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$item['uid']], $this->principal(), true);
            self::fail('too many open requests');
        } catch (McpCallRefused $refused) {
            self::assertSame('too_many_open_requests', $refused->reason);
        }
    }

    #[Test]
    public function theConnectorRunsTheToolsOnlyWithTheFeatureTheScopeAndTheSiteCapability(): void
    {
        [$item] = $this->proposal(12, 'Search');
        $call = fn (array $scopes): array => $this->get(McpToolExecutor::class)->execute('aqg_request_change_approval', ['siteIdentifier' => 'test', 'itemId' => (int)$item['uid']], ['scopes' => $scopes] + $this->principal());
        $refusal = static function (callable $run): string {
            try {
                $run();
                return 'ran';
            } catch (McpCallRefused $refused) {
                return $refused->reason;
            }
        };
        $all = ['content:read', 'suggestions:prepare', 'remediations:apply-approved'];
        $this->get(McpSiteSettingsRepository::class)->save('test', true, ['content:read', 'suggestions:prepare'], 1);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['mcpApprovedChanges'] = '0';
        self::assertSame('tool_not_available', $refusal(fn () => $call($all)), 'off: not even a granted token runs it');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['mcpApprovedChanges'] = '1';
        self::assertSame('scope_not_granted', $refusal(fn () => $call(['content:read', 'suggestions:prepare'])), 'suggestions:prepare never applies');
        self::assertSame('capability_off', $refusal(fn () => $call($all)), 'the site has not switched it on');

        // Switched on: the container's tools run as the token's user (the container's AI Fixes has no licence double here,
        // so the preview refuses: TYPO3-side entitlements still decide).
        $this->get(McpSiteSettingsRepository::class)->save('test', true, $all, 1);
        $answer = $call($all);
        self::assertSame('permission_denied', $answer['outcome']);
        self::assertSame('no_licence', $answer['reason']);
        self::assertSame(1, $this->rows('tx_a11y_mcp_change_approval'), 'no new approval request without the licence');
    }

    /**
     * Discover → propose: the editor's agent prepares a suggestion (AI Fixes, agent origin), then asks for approval.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function proposal(int $contentUid, string $value): array
    {
        $this->login(self::EDITOR);
        $this->answer = ['status' => 'suggestion', 'value' => $value, 'reason' => 'The form searches the shop.'];
        $workflow = $this->workflow();
        $prepared = $workflow->prepareForAgent([$this->findings[$contentUid]])['items'][0];
        self::assertSame('queued', $prepared['outcome'], (string)($prepared['reason'] ?? ''));
        $item = $this->item((int)$prepared['itemUid']);
        $workflow->generate((int)$item['uid'], (int)$item['row_version']);
        $item = $this->item((int)$item['uid']);
        self::assertSame(RemediationStatus::PENDING_REVIEW, $item['status'], (string)$item['status_reason']);

        $request = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => (int)$item['uid']], $this->principal(), true);
        self::assertSame('awaiting_approval', $request['outcome'], (string)($request['reason'] ?? ''));

        return [$item, $request];
    }

    /** What McpChangeApprovalController does for the person: display (review evidence), then the decision. */
    private function personApproves(string $ref): void
    {
        $this->login(self::EDITOR);
        $row = $this->approvals()->findByRef($ref);
        $this->workflow()->markViewed((int)$row['item_uid'], (int)$row['proposal_version']);
        self::assertTrue($this->approvals()->decide((int)$row['uid'], self::EDITOR, (string)$row['binding_hash'], true, time()));
    }

    private function show(string $ref): ResponseInterface
    {
        $router = $this->get(Router::class);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/mcp/change-approval?approval=' . $ref, 'GET'))
            ->withQueryParams(['approval' => $ref])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($router->getRoute('web_a11y.mcpChangeApproval')->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->controller()->showAction($request);
    }

    /** @param array<string, string> $body */
    private function decide(array $body): ResponseInterface
    {
        $router = $this->get(Router::class);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/mcp/change-approval/decide', 'POST'))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($router->getRoute('web_a11y.mcpChangeApprovalDecide')->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->controller()->decideAction($request);
    }

    /** @param array<string, string> $body */
    private function reject(array $body): ResponseInterface
    {
        $router = $this->get(Router::class);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/mcp/change-approval/reject', 'POST'))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($router->getRoute('web_a11y.mcpChangeApprovalReject')->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->controller()->rejectAction($request);
    }

    /** The container's controller with this test's workflow (licence and AI doubles), like the connector's graph. */
    private function controller(): McpChangeApprovalController
    {
        $container = $this->get(McpChangeApprovalController::class);
        $arguments = [];
        foreach ((new \ReflectionClass(McpChangeApprovalController::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $property = new \ReflectionProperty($container, $name);
            $arguments[$name] = $name === 'workflow' ? $this->workflow() : $property->getValue($container);
        }

        return new McpChangeApprovalController(...$arguments);
    }

    private function tools(?RichTextRecordWriter $writer = null): McpApprovedChangeTools
    {
        return new McpApprovedChangeTools($this->workflow($writer), $this->approvals(), $this->get(McpIntelligenceTools::class), $this->get(McpAuditRepository::class), $this->get(ConnectionPool::class));
    }

    private function approvals(): McpChangeApprovalRepository
    {
        return $this->get(McpChangeApprovalRepository::class);
    }

    private function site(): Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier('test');
    }

    /** @return array<string, mixed> */
    private function principal(string $token = self::TOKEN, int $user = self::EDITOR): array
    {
        return ['tokenId' => $token, 'tokenSuffix' => substr($token, -4), 'clientName' => 'Test agent', 'beUserUid' => $user,
            'scopes' => ['content:read', 'suggestions:prepare', 'remediations:apply-approved'], 'siteIdentifiers' => ['test'], 'expiresAt' => '2026-12-31T00:00:00Z'];
    }

    private function eventCount(int $itemUid, string $action): int
    {
        return count(array_filter($this->eventActions($itemUid), static fn (string $a): bool => $a === $action));
    }
}
