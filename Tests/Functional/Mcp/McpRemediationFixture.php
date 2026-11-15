<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Mcp\Intelligence\McpIntelligenceTools;
use Priebera\A11yQualityGate\Mcp\McpDeferredAuthorization;
use Priebera\A11yQualityGate\Mcp\McpDeferredWork;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Mcp\Remediation\McpSuggestionTools;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
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
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Shared fixture of the MCP security regression suites (release-gate findings 1 and 2): two sites, an administrator
 * and two editors, content findings on client-a page 2 (Kontakt) and client-b page 100, and the AI Fixes workflow with
 * the licence and AI configuration as test doubles — exactly as McpSuggestionToolsFunctionalTest builds it — so the
 * provider is reached only through TYPO3's HTTP client (answered by the tests' QA stand-in, never a real AI service).
 *
 * Page 2 is readable by its owner (editor A) and administrators only; editor B shares A's web mount and scopes and has
 * the queue permission, but no page permission on page 2.
 */
trait McpRemediationFixture
{
    protected const ADMIN = 1;
    protected const EDITOR_A = 2;
    protected const EDITOR_B = 3;
    protected const PAGE = 2;
    protected const SCOPES = ['findings:read', 'content:read', 'suggestions:prepare'];
    protected const HTML = '<p>Contact</p><button type="button" class="close"><span class="x"></span></button><button type="button"></button>';

    /** @var array<string, int> */
    protected array $issues = [];
    protected RemediationAccessService $access;
    protected EditorAiAccessService $editorAiAccess;
    /** @var \Closure(string): void resolver behaviour per site (throw to make AI not ready) */
    protected ?\Closure $resolveHook = null;

    protected function setUpRemediationFixture(): void
    {
        $pool = $this->get(ConnectionPool::class);
        foreach ([[1, 0, 'Client A', 1, 31, 0], [self::PAGE, 1, 'Kontakt', 0, 0, self::EDITOR_A], [100, 0, 'Client B', 1, 31, 0]] as [$uid, $pid, $title, $root, $everybody, $owner]) {
            $pool->getConnectionForTable('pages')->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'is_siteroot' => $root, 'slug' => '/' . strtolower($title),
                'perms_userid' => $owner, 'perms_user' => 31, 'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody, 'deleted' => 0, 'hidden' => 0]);
        }
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => self::PAGE, 'CType' => 'html', 'header' => 'Contact box', 'bodytext' => self::HTML, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 13, 'pid' => 100, 'CType' => 'html', 'header' => 'Other client', 'bodytext' => self::HTML, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::ADMIN, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0,
            'TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\n"]);
        $group = ['groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1,100', 'tables_select' => 'tt_content,pages', 'tables_modify' => 'tt_content',
            'non_exclude_fields' => 'tt_content:bodytext', 'explicit_allowdeny' => 'tt_content:CType:html'];
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 11, 'title' => 'Editors A'] + $group + [
            'TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\noptions.a11y_quality_gate.ai.editor = 1\noptions.a11y_quality_gate.ai.bulk = 0"]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 12, 'title' => 'Editors B'] + $group + [
            'TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\noptions.a11y_quality_gate.ai.editor = 1\noptions.a11y_quality_gate.ai.bulk = 1"]);
        foreach ([[self::EDITOR_A, 'editor-a', '11'], [self::EDITOR_B, 'editor-b', '12']] as [$uid, $name, $groups]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => $uid, 'username' => $name, 'admin' => 0, 'usergroup' => $groups, 'options' => 3,
                'workspace_perms' => 1, 'disable' => 0, 'deleted' => 0]);
        }

        $this->get(SiteWriter::class)->createNewBasicSite('client-a', 1, 'https://a.test/');
        $this->get(SiteWriter::class)->createNewBasicSite('client-b', 100, 'https://b.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->get(McpSiteSettingsRepository::class)->save('client-a', true, self::SCOPES, 1);
        $this->get(McpSiteSettingsRepository::class)->save('client-b', true, self::SCOPES, 1);

        $rule = $this->get(RuleRegistry::class)->getById('rte.button_label_missing');
        self::assertNotNull($rule);
        foreach ([['client-a', self::PAGE, 10, 'a'], ['client-b', 100, 13, 'b']] as [$site, $page, $uid, $key]) {
            foreach ($rule->check(new CheckContext(siteIdentifier: $site, pageUid: $page, sourceLangUid: 0, sourceTable: 'tt_content', sourceUid: $uid, sourceField: 'bodytext', content: self::HTML, cType: 'html', contextPath: 'Page:' . $page . ' > tt_content:' . $uid . ' > bodytext')) as $index => $violation) {
                $this->issues[$key . $index] = $this->insertIssue(['site_identifier' => $site, 'page_uid' => $page, 'source_uid' => $uid, 'source_type' => 'rte', 'rule_id' => 'rte.button_label_missing',
                    'severity' => 1, 'message' => $violation->message, 'context_path' => $violation->contextPath, 'context_snippet' => $violation->contextSnippet]);
            }
        }
        self::assertArrayHasKey('a1', $this->issues);
        self::assertArrayHasKey('b0', $this->issues);
    }

    /** The AI Fixes workflow with licence and AI configuration as doubles (one graph = one connector process). */
    protected function remediationWorkflow(): RemediationWorkflowService
    {
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn(true);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(function (string $site): \Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration {
            if ($this->resolveHook !== null) {
                ($this->resolveHook)($site);
            }
            return new \Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration('openai', 'sk-qa-mock', 'gpt-5.4-mini', 'site');
        });
        $configuration = $this->createMock(AiConfigurationRepositoryInterface::class);
        $configuration->method('isLinkTextSuggestionsEnabled')->willReturn(true);
        $this->editorAiAccess = new EditorAiAccessService($licence, $resolver, $this->get(AiSiteSettingsService::class), $configuration, $this->get(BackendUserService::class));
        // The provider chain the container builds, with the same licence and AI configuration doubles: the request goes
        // through the real task runner, usage recorder (tx_a11y_ai_usage) and OpenAI provider to TYPO3's HTTP client.
        $containerRunner = $this->get(\Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner::class);
        $taskRunner = new \Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner(
            $licence,
            $this->get(AiSiteSettingsService::class),
            $this->get(\Priebera\A11yQualityGate\Ai\Service\AiRateLimiter::class),
            $resolver,
            $this->get(\Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder::class),
            (new \ReflectionProperty($containerRunner, 'executors'))->getValue($containerRunner),
        );
        $container = $this->get(EditorAiRemediationService::class);
        $arguments = [];
        foreach ((new \ReflectionClass(EditorAiRemediationService::class))->getConstructor()->getParameters() as $parameter) {
            $arguments[$parameter->getName()] = match ($parameter->getName()) {
                'accessService' => $this->editorAiAccess,
                'taskRunner' => $taskRunner,
                default => (new \ReflectionProperty($container, $parameter->getName()))->getValue($container),
            };
        }
        $editorAi = new EditorAiRemediationService(...$arguments);
        $this->access = new RemediationAccessService(
            $this->get(BackendUserService::class),
            $this->get(ScopeAccessService::class),
            $this->get(BackendRecordAccessService::class),
            $this->editorAiAccess,
            $licence,
            $this->get(AccessControlService::class),
            $this->get(LocalFindingSourceAccessService::class),
        );

        return new RemediationWorkflowService(
            $this->get(RemediationItemRepository::class),
            $this->get(IssueRepository::class),
            $this->get(RemediationTargetResolver::class),
            $this->access,
            $this->get(ReferenceAltAiService::class),
            $editorAi,
            $this->get(EditorAiValueValidator::class),
            $this->get(ImageAltTextValidator::class),
            $this->get(ImageRemediationServiceInterface::class),
            $this->get(ImageFindingContextResolver::class),
            $this->get(ImageFindingVersionTokenService::class),
            $this->get(RuleMetadataPresentationServiceInterface::class),
            $this->get(ConnectionPool::class),
        );
    }

    /**
     * McpUserContext as the container builds it, but resetting this graph's own memo services (the doubles above are
     * not container services); the container's tagged set is asserted separately.
     */
    protected function userContextResetting(object ...$services): McpUserContext
    {
        return new McpUserContext(
            $this->get(ConnectionPool::class),
            $this->get(Context::class),
            $this->get(LanguageServiceFactory::class),
            $this->get(CacheManager::class),
            $this->get(SiteFinder::class),
            $this->get(AccessControlService::class),
            $services,
        );
    }

    protected function suggestionTools(McpDeferredWork $deferred, ?McpUserContext $userContext = null, ?McpDeferredAuthorization $authorization = null): McpSuggestionTools
    {
        $workflow = $this->remediationWorkflow();
        $userContext ??= $this->userContextResetting($this->access, $this->editorAiAccess);

        return new McpSuggestionTools($workflow, $this->get(RemediationItemRepository::class), $this->get(IssueRepository::class),
            $this->get(McpIntelligenceTools::class), $deferred, $userContext, $authorization);
    }

    protected function rowCount(string $table, string $where = '1=1', array $parameters = []): int
    {
        return (int)$this->get(ConnectionPool::class)->getConnectionForTable($table)->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where, $parameters);
    }

    /** @return array<string, mixed> */
    protected function item(int $itemUid): array
    {
        return (array)$this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item')
            ->fetchAssociative('SELECT * FROM tx_a11y_remediation_item WHERE uid = ?', [$itemUid]);
    }
}
