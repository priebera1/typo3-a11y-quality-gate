<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiContextBuilder;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiImageResolver;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRecordContextResolver;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTargetResolver;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskExecutorInterface;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetResolver;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Remediation\Workflow\RichTextRecordWriter;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared fixture of the direct-apply tests. AI Fixes beyond images (AQG 3.0.0): a reviewed rich-text or plain-HTML change is applied by AQG — scan finding →
 * generate → review → apply → save → verify — through the real workflow, DataHandler and AQG's own rule check, with a
 * deterministic provider double. The stored field changes in exactly one element; every other byte stays; the item
 * ends Verified; stale, unprovable, unauthorized and repeated applies write nothing.
 */
abstract class AbstractDirectApplyFunctionalTestCase extends AbstractFunctionalTestCase
{
    protected const ADMIN = 1;
    protected const EDITOR = 2;
    protected const READER = 3;

    protected const CONTACT = "<p>Our bakery in Lindenhof is open Monday to Saturday.\n  Directions, parking and our phone number: <a href=\"/contact\" class=\"more\">click here</a>.</p>\n<!-- <a href=\"/x\">click here</a> -->\n<p data-note=\"a > b\">Unrelated   <b>formatting</b>&nbsp;kept</p>";
    protected const IFRAMES = "<h2>Our location</h2>\n<iframe src=\"https://maps.example.test/embed?q=1\" width=\"600\"></iframe>\n<p>Opening hours below.</p>\n<iframe src=\"https://maps.example.test/embed?q=1\" width=\"600\"></iframe>";
    protected const BUTTON = '<form action="/search"><input type="search" name="q" aria-label="Search terms"><button type="submit" class="icon-search"></button></form>';
    protected const ICON_LINK = '<p>Visit <a href="https://shop.example.test/"><span class="icon-cart"></span></a> today.</p>';
    protected const HEADING = "<h3></h3>\n<p>We bake rye bread, spelt rolls and seasonal cakes every morning from regional flour.</p>";
    protected const PLAIN = '<div class="embed"><iframe src="https://video.example.test/v/42"></iframe></div>';

    /** @var array<int,int> tt_content uid => finding uid */
    protected array $findings = [];
    /** @var array<string,string> */
    protected array $answer = [];
    /** @var list<AiStructuredRequest> */
    protected array $requests = [];
    protected bool $providerDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n  - languageId: 1\n    title: Deutsch\n    locale: de_DE.UTF-8\n    base: /de/\n");

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Bakery', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Contact and directions', 'slug' => '/contact', 'doktype' => 1, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0]);
        $content = $pool->getConnectionForTable('tt_content');
        foreach ([
            [10, 'text', self::CONTACT, 0, 0], [11, 'text', self::IFRAMES, 0, 0], [12, 'text', self::BUTTON, 0, 0],
            [13, 'text', self::ICON_LINK, 0, 0], [14, 'text', self::HEADING, 0, 0], [15, 'html', self::PLAIN, 0, 0],
            // German translation of 10 with its own link.
            [20, 'text', '<p>Wegbeschreibung: <a href="/de/contact">hier klicken</a>.</p>', 1, 10],
        ] as [$uid, $cType, $html, $language, $parent]) {
            $content->insert('tt_content', ['uid' => $uid, 'pid' => 1, 'CType' => $cType, 'header' => 'Element ' . $uid, 'bodytext' => $html,
                'sys_language_uid' => $language, 'l18n_parent' => $parent, 'tstamp' => 1000]);
        }
        $users = $pool->getConnectionForTable('be_users');
        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 1, 'title' => 'editors', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'tt_content,pages', 'tables_modify' => 'tt_content', 'non_exclude_fields' => 'tt_content:bodytext',
            'explicit_allowdeny' => 'tt_content:CType:text,tt_content:CType:html', 'allowed_languages' => '0,1',
            'TSconfig' => "options.a11y_quality_gate.ai.editor = 1"]);
        $groups->insert('be_groups', ['uid' => 2, 'title' => 'readers', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'tt_content,pages', 'tables_modify' => '', 'allowed_languages' => '0,1', 'TSconfig' => "options.a11y_quality_gate.ai.editor = 1"]);
        foreach ([[self::ADMIN, 'admin', 1, ''], [self::EDITOR, 'editor', 0, '1'], [self::READER, 'reader', 0, '2']] as [$uid, $name, $admin, $usergroup]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'admin' => $admin, 'usergroup' => $usergroup, 'disable' => 0, 'deleted' => 0]);
        }

        $this->finding(10, 'rte.non_descriptive_link', self::CONTACT);
        $this->finding(11, 'rte.iframe_missing_title', self::IFRAMES, 1);
        $this->finding(12, 'rte.button_label_missing', self::BUTTON);
        $this->finding(13, 'rte.empty_link', self::ICON_LINK);
        $this->finding(14, 'rte.empty_heading', self::HEADING);
        $this->finding(15, 'rte.iframe_missing_title', self::PLAIN, 0, 'html');
        $this->finding(20, 'rte.non_descriptive_link', (string)$this->bodytext(20), 0, 'text', 1);
    }

    protected function finding(int $uid, string $ruleId, string $html, int $occurrence = 0, string $cType = 'text', int $language = 0): void
    {
        $rule = $this->get(RuleRegistry::class)->getById($ruleId);
        self::assertNotNull($rule, $ruleId);
        $violations = $rule->check(new CheckContext(
            siteIdentifier: 'test', pageUid: 1, sourceLangUid: $language, sourceTable: 'tt_content', sourceUid: $uid, sourceField: 'bodytext',
            content: $html, cType: $cType, contextPath: 'Page:1 > tt_content:' . $uid . ' > bodytext',
        ));
        self::assertArrayHasKey($occurrence, $violations, $ruleId . ' in element ' . $uid);
        $this->findings[$uid] = $this->insertIssue([
            'source_uid' => $uid, 'source_type' => 'rte', 'source_lang_uid' => $language, 'rule_id' => $ruleId, 'severity' => 1,
            'message' => $violations[$occurrence]->message, 'context_path' => $violations[$occurrence]->contextPath, 'context_snippet' => $violations[$occurrence]->contextSnippet,
        ]);
    }

    /** @return array<string,mixed> */
    protected function generateAndView(int $contentUid, bool $view = true): array
    {
        $workflow = $this->workflow();
        $prepared = $workflow->prepare([$this->findings[$contentUid]], RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0];
        self::assertSame('queued', $prepared['outcome'], (string)($prepared['reason'] ?? ''));
        $item = $this->item((int)$prepared['itemUid']);
        $workflow->generate((int)$item['uid'], (int)$item['row_version']);
        $item = $this->item((int)$item['uid']);
        if ($view) {
            self::assertSame(RemediationStatus::PENDING_REVIEW, $item['status'], (string)$item['status_reason']);
            $workflow->markViewed((int)$item['uid'], (int)$item['proposal_version']);
        }

        return $item;
    }

    /** @return array<string,mixed> */
    protected function item(int $uid): array
    {
        return $this->get(RemediationItemRepository::class)->findByUid($uid);
    }

    protected function login(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    protected function workflow(?RichTextRecordWriter $writer = null): RemediationWorkflowService
    {
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn(true);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturn(new AiProviderConfiguration('anthropic', 'synthetic-unused', 'claude-haiku-5-5', 'site', 'qa'));
        $resolver->method('status')->willReturn(['provider' => 'anthropic', 'selectedModelId' => 'claude-haiku-5-5']);
        $configuration = $this->createMock(AiConfigurationRepositoryInterface::class);
        $configuration->method('isLinkTextSuggestionsEnabled')->willReturn(true);
        $users = $this->get(BackendUserService::class);
        $settings = $this->get(AiSiteSettingsService::class);
        $editorAiAccess = new EditorAiAccessService($licence, $resolver, $settings, $configuration, $users);

        $executor = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $executor->method('supports')->willReturn(true);
        $executor->method('execute')->willReturnCallback(function (AiStructuredRequest $request): AiStructuredResponse {
            if ($this->providerDown) {
                throw new AiProviderException('The provider is not reachable.');
            }
            $this->requests[] = $request;
            return new AiStructuredResponse($this->answer, new AiUsage(800, 60, true), 'anthropic', 'claude-haiku-5-5');
        });
        $runner = new AiStructuredTaskRunner($licence, $settings, $this->get(AiRateLimiter::class), $resolver,
            new AiUsageRecorder($this->get(AiUsageRepository::class), new AiUsageMeter(), $users, $this->get(\Priebera\A11yQualityGate\Ai\Usage\AiBudgetService::class)), [$executor]);
        $editorAi = new EditorAiRemediationService(
            $this->get(EditorAiRecordContextResolver::class), $editorAiAccess, $this->get(EditorFindingTargetResolver::class),
            $this->get(EditorAiContextBuilder::class), $this->get(EditorAiValueValidator::class), $this->get(EditorAiImageResolver::class),
            $runner, $settings, $this->get(RuleRegistry::class), $this->get(RuleConfigurationService::class),
        );
        $access = new RemediationAccessService(
            $users, $this->get(ScopeAccessService::class), $this->get(BackendRecordAccessService::class), $editorAiAccess, $licence,
            $this->get(AccessControlService::class), $this->get(LocalFindingSourceAccessService::class),
        );

        return new RemediationWorkflowService(
            $this->get(RemediationItemRepository::class), $this->get(IssueRepository::class), new RemediationTargetResolver($this->get(ImageFindingContextResolver::class), $editorAi),
            $access, $this->get(ReferenceAltAiService::class), $editorAi, $this->get(EditorAiValueValidator::class),
            $this->get(ImageAltTextValidator::class), $this->get(ImageRemediationServiceInterface::class), $this->get(ImageFindingContextResolver::class),
            $this->get(ImageFindingVersionTokenService::class), $this->get(RuleMetadataPresentationServiceInterface::class),
            GeneralUtility::makeInstance(ConnectionPool::class),
            richTextWriter: $writer,
        );
    }

    /** @return list<string> */
    protected function eventActions(int $itemUid): array
    {
        return array_map('strval', GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_event')
            ->executeQuery('SELECT action FROM tx_a11y_remediation_event WHERE item_uid = ' . $itemUid . ' ORDER BY uid')->fetchFirstColumn());
    }

    /** @return list<string> */
    protected function eventDetails(int $itemUid): array
    {
        return array_map('strval', GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_event')
            ->executeQuery('SELECT detail FROM tx_a11y_remediation_event WHERE item_uid = ' . $itemUid . ' ORDER BY uid')->fetchFirstColumn());
    }

    protected function rows(string $table): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    protected function bodytext(int $uid): string
    {
        return (string)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->fetchOne('SELECT bodytext FROM tt_content WHERE uid = ' . $uid);
    }

    protected function setBodytext(int $uid, string $html): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['bodytext' => $html, 'tstamp' => time()], ['uid' => $uid]);
    }
}
