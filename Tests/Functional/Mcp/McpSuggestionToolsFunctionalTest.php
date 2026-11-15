<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Mcp\Intelligence\McpIntelligenceTools;
use Priebera\A11yQualityGate\Mcp\McpCallRefused;
use Priebera\A11yQualityGate\Mcp\McpDeferredAuthorization;
use Priebera\A11yQualityGate\Mcp\McpDeferredWork;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Mcp\Remediation\McpSuggestionTools;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetResolver;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Agent access, suggestions:prepare (connector side). The tool exists only behind its own scope AND the site's
 * capability; it prepares AI Fixes items as the token's TYPO3 user through the real workflow (licence and AI
 * configuration fixed, no provider is called: generate=false), never twice, never for another site, the rendered page
 * or without the queue permission for several findings — and the person who owns the token approves in the backend.
 */
final class McpSuggestionToolsFunctionalTest extends AbstractFunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 2;
    private const SCOPES = ['findings:read', 'content:read', 'suggestions:prepare'];
    private const HTML = '<p>Contact</p><button type="button" class="close"><span class="x"></span></button><button type="button"></button>';

    /** @var array<string, int> */
    private array $issues = [];
    private RemediationAccessService $access;
    private McpDeferredWork $deferred;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        foreach ([[1, 0, 'Client A', 1], [2, 1, 'Kontakt', 0], [100, 0, 'Client B', 1]] as [$uid, $pid, $title, $root]) {
            $pool->getConnectionForTable('pages')->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'is_siteroot' => $root, 'slug' => '/' . strtolower($title),
                'perms_userid' => 0, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        }
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 2, 'CType' => 'html', 'header' => 'Contact box', 'bodytext' => self::HTML, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 13, 'pid' => 100, 'CType' => 'html', 'header' => 'Other client', 'bodytext' => self::HTML, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::ADMIN, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0,
            'TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\n"]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 11, 'title' => 'Editors', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'tt_content,pages', 'tables_modify' => 'tt_content', 'non_exclude_fields' => 'tt_content:bodytext', 'explicit_allowdeny' => 'tt_content:CType:html',
            'TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\noptions.a11y_quality_gate.ai.editor = 1\noptions.a11y_quality_gate.ai.bulk = 0"]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::EDITOR, 'username' => 'editor', 'admin' => 0, 'usergroup' => '11', 'options' => 3, 'workspace_perms' => 1, 'disable' => 0, 'deleted' => 0]);

        $this->get(SiteWriter::class)->createNewBasicSite('client-a', 1, 'https://a.test/');
        $this->get(SiteWriter::class)->createNewBasicSite('client-b', 100, 'https://b.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->get(McpSiteSettingsRepository::class)->save('client-a', true, self::SCOPES, 1);
        $this->get(McpSiteSettingsRepository::class)->save('client-b', true, ['findings:read', 'content:read'], 1);

        $rule = $this->get(RuleRegistry::class)->getById('rte.button_label_missing');
        self::assertNotNull($rule);
        foreach ([['client-a', 2, 10, 'a'], ['client-b', 100, 13, 'b']] as [$site, $page, $uid, $key]) {
            foreach ($rule->check(new CheckContext(siteIdentifier: $site, pageUid: $page, sourceLangUid: 0, sourceTable: 'tt_content', sourceUid: $uid, sourceField: 'bodytext', content: self::HTML, cType: 'html', contextPath: 'Page:' . $page . ' > tt_content:' . $uid . ' > bodytext')) as $index => $violation) {
                $this->issues[$key . $index] = $this->insertIssue(['site_identifier' => $site, 'page_uid' => $page, 'source_uid' => $uid, 'source_type' => 'rte', 'rule_id' => 'rte.button_label_missing',
                    'severity' => 1, 'message' => $violation->message, 'context_path' => $violation->contextPath, 'context_snippet' => $violation->contextSnippet]);
            }
        }
        $this->issues['rendered'] = $this->insertIssue(['site_identifier' => 'client-a', 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 2, 'source_field' => '__rendered_html',
            'source_type' => 'rendered', 'rule_id' => 'rendered.empty_button', 'severity' => 1, 'message' => 'Button has no name.']);
        self::assertArrayHasKey('a1', $this->issues);
    }

    #[Test]
    public function theToolsNeedTheirOwnScopeAndTheSiteCapability(): void
    {
        $this->assertRefused('scope_not_granted', 'aqg_prepare_suggestions', ['siteIdentifier' => 'client-a', 'findingIds' => [$this->issues['a0']]], ['findings:read', 'content:read']);
        $this->assertRefused('scope_not_granted', 'aqg_get_suggestion_status', ['siteIdentifier' => 'client-a', 'findingIds' => [$this->issues['a0']]], ['content:read']);
        // Granted in the token, but the site's administrator has not switched preparation on for client-b.
        $this->assertRefused('capability_off', 'aqg_prepare_suggestions', ['siteIdentifier' => 'client-b', 'findingIds' => [$this->issues['b0']]], self::SCOPES, ['client-a', 'client-b']);
        $this->assertRefused('site_not_available', 'aqg_prepare_suggestions', ['siteIdentifier' => 'client-b', 'findingIds' => [$this->issues['b0']]]);
        self::assertSame(0, $this->rows());
    }

    #[Test]
    public function anAgentPreparesOnlyEligibleFindingsOfTheSiteOnceAsItsUserAndNothingIsApplied(): void
    {
        $this->setUpBackendUser(self::ADMIN);
        $tools = $this->tools();
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('client-a');
        $user = $GLOBALS['BE_USER'];

        $result = $tools->prepare($user, $site, ['findingIds' => [$this->issues['a0'], $this->issues['rendered'], $this->issues['b0'], 999999], 'generate' => false], true);

        $byId = array_column($result['results'], null, 'findingId');
        self::assertSame('queued', $byId[$this->issues['a0']]['outcome']);
        self::assertSame(RemediationStatus::PREPARING, $byId[$this->issues['a0']]['item']['status']);
        self::assertSame('agent', $byId[$this->issues['a0']]['item']['origin']);
        self::assertStringContainsString('item=', (string)$byId[$this->issues['a0']]['item']['reviewPath']);
        self::assertStringNotContainsString('token=', (string)$byId[$this->issues['a0']]['item']['reviewPath']);
        self::assertSame(['skipped', 'rendered_output'], [$byId[$this->issues['rendered']]['outcome'], $byId[$this->issues['rendered']]['reason']], 'The rendered page has no editable source.');
        self::assertSame('finding_not_found', $byId[$this->issues['b0']]['reason'], 'Another site\'s finding answers like an unknown one.');
        self::assertSame('finding_not_found', $byId[999999]['reason']);
        self::assertTrue($result['humanReviewRequired']);
        self::assertFalse($result['changed']);
        self::assertSame(1, $this->rows());

        // A repeated call (an agent retry) creates nothing new.
        $again = array_column($tools->prepare($user, $site, ['findingIds' => [$this->issues['a0']], 'generate' => false], true)['results'], null, 'findingId');
        self::assertSame('already_queued', $again[$this->issues['a0']]['outcome']);
        self::assertSame($byId[$this->issues['a0']]['item']['itemId'], $again[$this->issues['a0']]['item']['itemId']);
        self::assertSame(1, $this->rows());

        // The person who owns the token approves it in the backend like an own request; nothing was written yet.
        $item = $this->get(RemediationItemRepository::class)->findByUid($byId[$this->issues['a0']]['item']['itemId']);
        self::assertSame('', $this->access->applyRefusal($item, false));
        self::assertSame(self::HTML, (string)$this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->fetchOne('SELECT bodytext FROM tt_content WHERE uid = 10'));

        // Generation is never part of the answer (the Gateway waits only seconds): it is left as one job for after it.
        self::assertSame(0, $this->deferred->count(), 'generate=false schedules nothing.');
        // An item the same person prepared in the backend (not the agent's) is never generated on the agent's behalf.
        $items = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item');
        $itemId = $byId[$this->issues['a0']]['item']['itemId'];
        $items->update('tx_a11y_remediation_item', ['origin' => 'editor_request'], ['uid' => $itemId]);
        $own = array_column($tools->prepare($user, $site, ['findingIds' => [$this->issues['a0']]], true)['results'], null, 'findingId');
        self::assertArrayNotHasKey('generation', $own[$this->issues['a0']]);
        self::assertSame(0, $this->deferred->count(), 'a backend request of this user is not paid for by the agent');
        $items->update('tx_a11y_remediation_item', ['origin' => 'agent'], ['uid' => $itemId]);
        // Finding 2: outside an attested call (no call context, as for an unkeyed installation) nothing is scheduled.
        $unattested = array_column($tools->prepare($user, $site, ['findingIds' => [$this->issues['a0']]], true)['results'], null, 'findingId');
        self::assertSame('not_started', $unattested[$this->issues['a0']]['generation']);
        self::assertSame(0, $this->deferred->count());
        // Within an attested call (McpCallRunner::run() begins it) the job is left for after the answer.
        $this->deferred->begin(['callId' => 'aaaaaaaa-0000-4000-8000-000000000001', 'attestation' => 'h.p.s', 'claims' => ['jti' => 'attested-call-jti-0001']]);
        $scheduled = array_column($tools->prepare($user, $site, ['findingIds' => [$this->issues['a0']]], true)['results'], null, 'findingId');
        self::assertSame('scheduled', $scheduled[$this->issues['a0']]['generation']);
        self::assertSame(1, $this->deferred->count());
        $this->deferred->clear();

        $this->deferred->begin(['callId' => 'aaaaaaaa-0000-4000-8000-000000000002', 'attestation' => 'h.p.s', 'claims' => ['jti' => 'attested-call-jti-0002']]);
        $status = $tools->status($user, $site, ['findingIds' => [$this->issues['a0']]], true);
        self::assertSame('scheduled', $status['entries'][0]['item']['generation'], 'Asking for the status continues the next waiting suggestion.');
        self::assertSame(1, $this->deferred->count());
        $this->deferred->clear();
        self::assertTrue($status['entries'][0]['findingOpen']);
        self::assertSame(RemediationStatus::PREPARING, $status['entries'][0]['item']['status']);
    }

    #[Test]
    public function severalFindingsAtOnceNeedTheQueuePermissionAndAPageIsLimitedToItsEligibleFindings(): void
    {
        $this->setUpBackendUser(self::EDITOR);
        $tools = $this->tools();
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('client-a');

        try {
            $tools->prepare($GLOBALS['BE_USER'], $site, ['findingIds' => [$this->issues['a0'], $this->issues['a1']], 'generate' => false], true);
            self::fail('Several findings need ai.bulk, as in the backend.');
        } catch (McpCallRefused $refused) {
            self::assertSame('permission_denied', $refused->reason);
        }
        self::assertSame(0, $this->rows());

        $this->setUpBackendUser(self::ADMIN);
        $page = $tools->prepare($GLOBALS['BE_USER'], $site, ['pageUid' => 2, 'generate' => false], false);
        self::assertSame([$this->issues['a0'], $this->issues['a1']], array_column($page['results'], 'findingId'), 'The page\'s content findings, not the rendered one.');
        self::assertArrayNotHasKey('untrusted', $page['results'][0]['item'], 'Without content:read no record content is returned.');
        self::assertSame(2, $this->rows());
    }

    /**
     * Live MCP QA 2026-11-14 (findings 74141, 74615, 74146): agents prepared blind and got bare codes. Before anything is
     * prepared, aqg_get_suggestion_status now answers per finding whether AI Fixes can prepare it and why not (AI Fixes'
     * own preflight as this user: nothing is created, no AI request), prepare explains every skip, and an item without
     * a suggestion carries its reason and — with content:read, as untrusted text — the model's explanation.
     */
    #[Test]
    public function agentsLearnBeforePreparingWhetherAiFixesCanPrepareAFindingAndWhy(): void
    {
        @mkdir($this->instancePath . '/fileadmin', 0777, true);
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', ['uid' => 1, 'pid' => 0, 'name' => 'fileadmin', 'driver' => 'Local', 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1, 'is_online' => 1,
            'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field><field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>']);
        // The live QA fixture: a new-window link (no AI fix exists) and a redundant alt text of an image file that is
        // not in the storage.
        $html = '<p>Visit <a href="https://example.org" target="_blank">Example</a></p><img src="fileadmin/aqg-missing-team.jpg" alt="photo of team photo">';
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 11, 'pid' => 2, 'CType' => 'html', 'header' => 'QA box', 'bodytext' => $html, 'deleted' => 0, 'hidden' => 0]);
        foreach (['newWindow' => 'rte.link_new_window_no_warning', 'redundantAlt' => 'rte.img_alt_redundant_phrase'] as $key => $ruleId) {
            $rule = $this->get(RuleRegistry::class)->getById($ruleId);
            self::assertNotNull($rule);
            $violation = $rule->check(new CheckContext(siteIdentifier: 'client-a', pageUid: 2, sourceLangUid: 0, sourceTable: 'tt_content', sourceUid: 11, sourceField: 'bodytext', content: $html, cType: 'html', contextPath: 'Page:2 > tt_content:11 > bodytext'))[0];
            $this->issues[$key] = $this->insertIssue(['site_identifier' => 'client-a', 'page_uid' => 2, 'source_uid' => 11, 'source_type' => 'rte', 'rule_id' => $ruleId,
                'severity' => 2, 'message' => $violation->message, 'context_path' => $violation->contextPath, 'context_snippet' => $violation->contextSnippet]);
        }
        $this->setUpBackendUser(self::ADMIN);
        $tools = $this->tools();
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('client-a');
        $user = $GLOBALS['BE_USER'];

        $status = $tools->status($user, $site, ['findingIds' => [$this->issues['a0'], $this->issues['newWindow'], $this->issues['redundantAlt'], $this->issues['rendered']]], true);
        $eligibility = array_column(array_map(static fn (array $entry): array => ['id' => $entry['findingId']] + $entry['eligibility'], $status['entries']), null, 'id');
        self::assertSame(0, $this->rows(), 'Asking creates nothing.');
        self::assertTrue($eligibility[$this->issues['a0']]['canPrepare']);
        self::assertSame(['ready', '', 'button_label'], [$eligibility[$this->issues['a0']]['outcome'], $eligibility[$this->issues['a0']]['reason'], $eligibility[$this->issues['a0']]['aiKind']]);
        foreach ([
            'newWindow' => ['no_safe_action', 'no safe automatic action'],
            'redundantAlt' => ['image_missing', 'does not exist in a TYPO3 file storage'],
            'rendered' => ['rendered_output', 'cannot prove which record field'],
        ] as $key => [$reason, $text]) {
            $entry = $eligibility[$this->issues[$key]];
            self::assertFalse($entry['canPrepare'], $key);
            self::assertSame($reason, $entry['reason'], $key);
            self::assertTrue($entry['manualFixNeeded'], $key);
            self::assertStringContainsString($text, $entry['reasonText'], $key);
        }

        // Preparing them anyway explains each skip the same way; only the eligible finding gets an item.
        $prepared = $tools->prepare($user, $site, ['findingIds' => [$this->issues['a0'], $this->issues['newWindow'], $this->issues['redundantAlt']], 'generate' => false], true);
        $byId = array_column($prepared['results'], null, 'findingId');
        self::assertSame('queued', $byId[$this->issues['a0']]['outcome']);
        self::assertArrayNotHasKey('reasonText', $byId[$this->issues['a0']]);
        self::assertSame($eligibility[$this->issues['newWindow']]['reasonText'], $byId[$this->issues['newWindow']]['reasonText']);
        self::assertSame(['image_missing', true], [$byId[$this->issues['redundantAlt']]['reason'], $byId[$this->issues['redundantAlt']]['manualFixNeeded']]);
        self::assertNotEmpty(array_filter($prepared['notes'], static fn (string $note): bool => str_contains($note, 'manualFixNeeded')));
        self::assertSame(1, $this->rows());
        // Also when the only skip is refused before the workflow (the rendered page).
        $rendered = $tools->prepare($user, $site, ['findingIds' => [$this->issues['rendered']], 'generate' => false], true);
        self::assertSame([true, 'rendered_output'], [$rendered['results'][0]['manualFixNeeded'], $rendered['results'][0]['reason']]);
        self::assertNotEmpty(array_filter($rendered['notes'], static fn (string $note): bool => str_contains($note, 'manualFixNeeded')));

        // "No suggestion" (live finding 74146): the reason code and label, and the model's explanation as untrusted text.
        $itemId = (int)$byId[$this->issues['a0']]['item']['itemId'];
        $pool->getConnectionForTable('tx_a11y_remediation_item')->update('tx_a11y_remediation_item', ['status' => RemediationStatus::NO_SUGGESTION, 'status_reason' => 'needs_review',
            'explanation' => 'The button has no visible text or surrounding text, so its action cannot be determined.'], ['uid' => $itemId]);
        $item = $tools->status($user, $site, ['itemIds' => [$itemId]], true)['entries'][0]['item'];
        self::assertSame('needs_review', $item['reason']);
        self::assertSame('The AI found no safe suggestion. Please fix it yourself.', $item['reasonText']);
        self::assertStringContainsString('cannot be determined', $item['untrusted']['explanation']);
        self::assertArrayNotHasKey('untrusted', $tools->status($user, $site, ['itemIds' => [$itemId]], false)['entries'][0]['item'], 'Without content:read no model text.');
        self::assertArrayNotHasKey('eligibility', $tools->status($user, $site, ['findingIds' => [$this->issues['a0']]], true)['entries'][0], 'A finding with an item reports the item.');

        // Rule guidance says up front what AI Fixes can do for a rule (AQG's catalog only, no finding data).
        $intelligence = $this->get(McpIntelligenceTools::class);
        $limits = static fn (string $ruleId): string => implode("\n", $intelligence->getRemediationGuidance($user, $site, ['ruleId' => $ruleId], true)['limits']);
        self::assertStringContainsString('AI Fixes: an agent with suggestions:prepare can ask for a suggestion for this rule (button label)', $limits('rte.button_label_missing'));
        self::assertStringContainsString('AQG looks at the image file', $limits('rte.img_alt_redundant_phrase'));
        self::assertStringContainsString('AI Fixes: not available for this rule', $limits('rte.link_new_window_no_warning'));
        self::assertStringContainsString('AI Fixes: not available for findings of the rendered page', $limits('rendered.empty_link'));
        self::assertStringContainsString('rule-based fix without AI', $limits('rte.heading_hierarchy_jump'));
    }

    private function tools(): McpSuggestionTools
    {
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn(true);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturn(new AiProviderConfiguration('openai', 'sk-test', 'gpt-test', 'site'));
        $configuration = $this->createMock(AiConfigurationRepositoryInterface::class);
        $configuration->method('isLinkTextSuggestionsEnabled')->willReturn(true);
        $editorAiAccess = new EditorAiAccessService($licence, $resolver, $this->get(AiSiteSettingsService::class), $configuration, $this->get(BackendUserService::class));
        $this->access = new RemediationAccessService(
            $this->get(BackendUserService::class),
            $this->get(ScopeAccessService::class),
            $this->get(BackendRecordAccessService::class),
            $editorAiAccess,
            $licence,
            $this->get(AccessControlService::class),
            $this->get(LocalFindingSourceAccessService::class),
        );
        $workflow = new RemediationWorkflowService(
            $this->get(RemediationItemRepository::class),
            $this->get(IssueRepository::class),
            $this->get(RemediationTargetResolver::class),
            $this->access,
            $this->get(ReferenceAltAiService::class),
            $this->get(EditorAiRemediationService::class),
            $this->get(EditorAiValueValidator::class),
            $this->get(ImageAltTextValidator::class),
            $this->get(ImageRemediationServiceInterface::class),
            $this->get(ImageFindingContextResolver::class),
            $this->get(ImageFindingVersionTokenService::class),
            $this->get(RuleMetadataPresentationServiceInterface::class),
            $this->get(ConnectionPool::class),
        );

        $this->deferred = new McpDeferredWork();

        return new McpSuggestionTools($workflow, $this->get(RemediationItemRepository::class), $this->get(IssueRepository::class), $this->get(McpIntelligenceTools::class), $this->deferred, $this->get(McpUserContext::class), $this->get(McpDeferredAuthorization::class));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $scopes
     * @param list<string> $sites
     */
    private function assertRefused(string $reason, string $tool, array $arguments, array $scopes = self::SCOPES, array $sites = ['client-a']): void
    {
        try {
            $this->get(McpToolExecutor::class)->execute($tool, $arguments, [
                'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'tokenSuffix' => 'test', 'clientName' => 'Test agent', 'beUserUid' => self::ADMIN,
                'scopes' => $scopes, 'siteIdentifiers' => $sites, 'expiresAt' => '2027-12-01T00:00:00Z',
            ]);
            self::fail('Expected refusal ' . $reason);
        } catch (McpCallRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function rows(): int
    {
        return (int)$this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item')->fetchOne('SELECT COUNT(*) FROM tx_a11y_remediation_item');
    }
}
