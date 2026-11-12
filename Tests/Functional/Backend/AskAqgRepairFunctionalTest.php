<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiImageResolver;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgRepairIntent;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgRepairService;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgService;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContext;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantService;
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
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Ask AQG repair workflow against a real database, the real content rules and the real AI Fixes workflow (licence
 * and provider configuration are fixed; no provider is ever called here).
 *
 * The reported case: "oprav mi túto stránku" with the Plain HTML element "AQG 09 - Local cancel 05" selected, whose
 * stored findings are a button without a name, an iframe without a title and an image without alt text, from a content
 * check older than 14 days.
 */
final class AskAqgRepairFunctionalTest extends AbstractFunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 3;
    private const OTHER_MOUNT_EDITOR = 4;

    private const HTML = '<p>Local cancel</p>'
        . '<button type="button" class="close"><span class="icon-x"></span></button>'
        . '<iframe src="https://www.youtube-nocookie.com/embed/aqg09"></iframe>'
        . '<img src="https://example.org/remote.jpg">';
    private const OTHER_HTML = '<p>Map</p><iframe src="https://maps.example.org/embed"></iframe>';

    /** @var array<string,int> */
    private array $findings = [];
    private bool $linkTextEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'AQG 09 - Cancel Local Scan', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Other page', 'doktype' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'html', 'header' => 'AQG 09 - Local cancel 05', 'bodytext' => self::HTML, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 11, 'pid' => 1, 'CType' => 'html', 'header' => 'Map', 'bodytext' => self::OTHER_HTML, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::ADMIN, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 11, 'title' => 'Editors', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'tt_content,pages', 'tables_modify' => 'tt_content', 'non_exclude_fields' => 'tt_content:bodytext', 'explicit_allowdeny' => 'tt_content:CType:html',
            'TSconfig' => "options.a11y_quality_gate.ai.editor = 1\noptions.a11y_quality_gate.ai.images = 1\noptions.a11y_quality_gate.ai.bulk = 0"]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 12, 'title' => 'Other mount', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '2',
            'tables_select' => 'tt_content,pages', 'tables_modify' => 'tt_content', 'non_exclude_fields' => 'tt_content:bodytext', 'explicit_allowdeny' => 'tt_content:CType:html',
            'TSconfig' => "options.a11y_quality_gate.ai.editor = 1\noptions.a11y_quality_gate.ai.bulk = 1"]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::EDITOR, 'username' => 'editor', 'admin' => 0, 'usergroup' => '11', 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::OTHER_MOUNT_EDITOR, 'username' => 'other', 'admin' => 0, 'usergroup' => '12', 'disable' => 0, 'deleted' => 0]);

        // The findings exactly as the content scan stores them (same rule, context path and snippet).
        foreach ([[10, self::HTML], [11, self::OTHER_HTML]] as [$uid, $html]) {
            foreach (['rte.button_label_missing', 'rte.iframe_missing_title', 'rte.img_alt_missing'] as $ruleId) {
                $rule = $this->get(RuleRegistry::class)->getById($ruleId);
                self::assertNotNull($rule, $ruleId);
                foreach ($rule->check(new CheckContext(
                    siteIdentifier: 'test',
                    pageUid: 1,
                    sourceLangUid: 0,
                    sourceTable: 'tt_content',
                    sourceUid: $uid,
                    sourceField: 'bodytext',
                    content: $html,
                    cType: 'html',
                    contextPath: 'Page:1 > tt_content:' . $uid . ' > bodytext',
                )) as $violation) {
                    $this->findings[$uid . ':' . $ruleId] = $this->insertIssue([
                        'source_uid' => $uid, 'source_type' => 'rte', 'rule_id' => $ruleId, 'severity' => 1,
                        'message' => $violation->message, 'context_path' => $violation->contextPath, 'context_snippet' => $violation->contextSnippet,
                    ]);
                }
            }
        }
        self::assertSame(['10:rte.button_label_missing', '10:rte.iframe_missing_title', '10:rte.img_alt_missing', '11:rte.iframe_missing_title'], array_keys($this->findings));
        // The content check these findings come from is older than 14 days.
        $this->insertScan(['root_pid' => 1, 'scope' => 'page', 'language_uid' => 0, 'started_at' => time() - 20 * 86400, 'finished_at' => time() - 20 * 86400 + 5]);
    }

    #[Test]
    public function theReportedPlainHtmlElementGetsAPlanWithTheTrueSupportPerFinding(): void
    {
        $this->login(self::ADMIN);
        $intent = AskAqgRepairIntent::detect('Oprav mi túto stránku');
        self::assertTrue($intent['repair']);

        $payload = $this->service()->plan($this->elementContext(), 'page', 'Oprav mi túto stránku', $intent);
        $repair = $payload['repair'];

        self::assertSame('repair', $payload['intent']);
        self::assertSame('aqg', $payload['source'], 'Answered without AI.');
        self::assertSame('record', $repair['target'], 'The selected scope (this content element) is used, not silently the page.');
        self::assertSame([['target' => 'page', 'label' => 'Show fixes for the whole page']], $repair['alternatives']);
        self::assertStringContainsString('selected scope is this content element', implode(' ', $repair['notices']));
        self::assertSame(3, $repair['counts']['found']);
        $states = array_column($repair['items'], 'state', 'ruleId');
        self::assertSame('ready', $states['rte.button_label_missing']);
        self::assertSame('ready', $states['rte.iframe_missing_title']);
        self::assertSame('blocked', $states['rte.img_alt_missing'], 'An external image cannot be sent: no alt text is invented.');
        $image = $repair['items'][array_search('rte.img_alt_missing', array_column($repair['items'], 'ruleId'), true)];
        self::assertStringContainsString('another address', $image['reason']);
        self::assertSame([$this->findings['10:rte.button_label_missing'], $this->findings['10:rte.iframe_missing_title']], $repair['prepareIds']);
        self::assertTrue($repair['freshness']['stale']);
        self::assertTrue($repair['ai']['ready']);
        self::assertSame(0, $this->rows('tx_a11y_remediation_item'), 'The plan prepares nothing.');
        self::assertSame(0, $this->rows('tx_a11y_ai_usage'), 'The plan sends nothing to a provider.');
        self::assertSame(self::HTML, $this->bodytext(10));
    }

    #[Test]
    public function iframeTitlesAreReportedAsBlockedWhenTheSiteSwitchIsOff(): void
    {
        $this->login(self::ADMIN);
        $this->linkTextEnabled = false;

        $repair = $this->service()->plan($this->elementContext(), 'page', 'Fix this element', AskAqgRepairIntent::detect('Fix this element'))['repair'];

        $iframe = $repair['items'][array_search('rte.iframe_missing_title', array_column($repair['items'], 'ruleId'), true)];
        self::assertSame('blocked', $iframe['state']);
        self::assertSame('AI link and iframe title suggestions are disabled for this site.', $iframe['reason']);
        self::assertSame([$this->findings['10:rte.button_label_missing']], $repair['prepareIds']);
    }

    #[Test]
    public function theWholePageIsUsedOnlyWhenChosenAndTheWebsiteNever(): void
    {
        $this->login(self::ADMIN);
        $service = $this->service();

        $page = $service->plan($this->elementContext(), 'page', 'oprav mi tuto stranku', AskAqgRepairIntent::detect('oprav mi tuto stranku'), 'page')['repair'];
        self::assertSame('page', $page['target']);
        self::assertSame(4, $page['counts']['found']);

        $site = $service->plan($this->elementContext(), 'site', 'Fix the whole website', AskAqgRepairIntent::detect('Fix the whole website'))['repair'];
        self::assertSame('record', $site['target'], 'A website instruction is not widened into a site-wide run.');
        self::assertStringContainsString('never for the whole website', implode(' ', $site['notices']));
    }

    #[Test]
    public function prepareCreatesAiFixesItemsOnlyForReadyFindingsOfTheTargetAndNeverTwice(): void
    {
        $this->login(self::ADMIN);
        $service = $this->service();
        $button = $this->findings['10:rte.button_label_missing'];
        $iframe = $this->findings['10:rte.iframe_missing_title'];
        $image = $this->findings['10:rte.img_alt_missing'];
        $otherElement = $this->findings['11:rte.iframe_missing_title'];

        $result = $service->prepare($this->elementContext(), 'record', [$button, $iframe, $image, $otherElement]);

        $byId = array_column($result['items'], null, 'id');
        self::assertSame('queued', $byId[$button]['outcome']);
        self::assertSame('queued', $byId[$iframe]['outcome']);
        self::assertTrue($byId[$button]['generate']);
        self::assertSame(RemediationStatus::PREPARING, $byId[$button]['status']);
        self::assertSame(['skipped', EditorAiImageResolver::EXTERNAL], [$byId[$image]['outcome'], $byId[$image]['reason']]);
        self::assertSame(['skipped', 'scope_restricted'], [$byId[$otherElement]['outcome'], $byId[$otherElement]['reason']], 'Another element is never prepared from this target.');
        self::assertSame(2, $this->rows('tx_a11y_remediation_item'));
        self::assertSame(0, $this->rows('tx_a11y_ai_usage'), 'Preparing creates items only; suggestions are generated per item afterwards.');

        // A second click or a retry: the same items, no new ones.
        $again = array_column($service->prepare($this->elementContext(), 'record', [$button, $iframe])['items'], null, 'id');
        self::assertSame('already_queued', $again[$button]['outcome']);
        self::assertSame($byId[$button]['itemUid'], $again[$button]['itemUid']);
        self::assertFalse($again[$button]['generate'], 'An item that already existed is linked, never generated from here (not in the estimate).');
        self::assertSame(2, $this->rows('tx_a11y_remediation_item'));

        // Once a suggestion exists, the plan links it and nothing generates it again ...
        $repository = $this->get(RemediationItemRepository::class);
        $item = $repository->findByUid($byId[$button]['itemUid']);
        self::assertTrue($repository->transition((int)$item['uid'], (int)$item['row_version'], [RemediationStatus::PREPARING], RemediationStatus::PENDING_REVIEW, ['proposed_value' => 'Close dialog', 'proposal_version' => 1]));
        $third = array_column($service->prepare($this->elementContext(), 'record', [$button])['items'], null, 'id');
        self::assertSame(['already_queued', RemediationStatus::PENDING_REVIEW, false], [$third[$button]['outcome'], $third[$button]['status'], $third[$button]['generate']]);
        $plan = $service->plan($this->elementContext(), 'page', 'Fix this element', AskAqgRepairIntent::detect('Fix this element'))['repair'];
        $queued = $plan['items'][array_search('rte.button_label_missing', array_column($plan['items'], 'ruleId'), true)];
        self::assertSame(['queued', $byId[$button]['itemUid']], [$queued['state'], $queued['itemUid']]);
        self::assertNotContains($button, $plan['prepareIds']);

        // ... and a delayed retry of the generate step with the row version of the first click is refused (no second charge).
        try {
            $this->workflow()->generate($byId[$button]['itemUid'], $byId[$button]['rowVersion']);
            self::fail('A stale row version must not regenerate a reviewed proposal.');
        } catch (RemediationException $exception) {
            self::assertSame('changed_concurrently', $exception->safeCode);
        }
        self::assertSame('Close dialog', (string)$repository->findByUid($byId[$button]['itemUid'])['proposed_value']);
        self::assertSame(0, $this->rows('tx_a11y_ai_usage'));
        self::assertSame(self::HTML, $this->bodytext(10), 'Nothing is written to the record before an approved review.');
    }

    #[Test]
    public function withoutTheQueuePermissionFindingsArePreparedOneAtATime(): void
    {
        $this->login(self::EDITOR);
        $service = $this->service();
        $repair = $service->plan($this->elementContext(), 'page', 'Fix this element', AskAqgRepairIntent::detect('Fix this element'))['repair'];
        self::assertFalse($repair['bulk']);

        try {
            $service->prepare($this->elementContext(), 'record', [$this->findings['10:rte.button_label_missing'], $this->findings['10:rte.iframe_missing_title']]);
            self::fail('Several at once need ai.bulk.');
        } catch (EditorAiException $exception) {
            self::assertSame('permission_denied', $exception->safeCode);
        }
        $one = $service->prepare($this->elementContext(), 'record', [$this->findings['10:rte.button_label_missing']])['items'][0];
        self::assertSame('queued', $one['outcome']);
        self::assertSame(RemediationAccessService::ORIGIN_EDITOR_REQUEST, (string)$this->get(RemediationItemRepository::class)->findByUid($one['itemUid'])['origin']);
    }

    #[Test]
    public function aUserWithoutAccessToThePageSeesAndPreparesNothingEvenWithAForgedContext(): void
    {
        $this->login(self::OTHER_MOUNT_EDITOR);
        $service = $this->service();

        $repair = $service->plan($this->elementContext(), 'page', 'Fix this element', AskAqgRepairIntent::detect('Fix this element'))['repair'];
        self::assertSame(0, $repair['counts']['found']);
        self::assertSame([], $repair['items']);

        try {
            $service->prepare($this->elementContext(), 'record', [$this->findings['10:rte.button_label_missing']]);
            self::fail('Findings of an inaccessible page are never prepared.');
        } catch (EditorAiException $exception) {
            self::assertSame('scope_restricted', $exception->safeCode);
        }
        self::assertSame(0, $this->rows('tx_a11y_remediation_item'));
    }

    #[Test]
    public function aFindingThatNoLongerMatchesTheSavedContentIsStaleAndNotPrepared(): void
    {
        $this->login(self::ADMIN);
        // The editor fixed the button in the meantime; the stored finding is from the old check.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['bodytext' => str_replace('<button type="button" class="close">', '<button type="button" class="close" aria-label="Close">', self::HTML)], ['uid' => 10]);

        $repair = $this->service()->plan($this->elementContext(), 'page', 'Fix this element', AskAqgRepairIntent::detect('Fix this element'))['repair'];

        $button = $repair['items'][array_search('rte.button_label_missing', array_column($repair['items'], 'ruleId'), true)];
        self::assertSame('stale', $button['state']);
        self::assertNotContains($this->findings['10:rte.button_label_missing'], $repair['prepareIds']);
        $result = $this->service()->prepare($this->elementContext(), 'record', [$this->findings['10:rte.button_label_missing']])['items'][0];
        self::assertSame(['skipped', 'target_changed'], [$result['outcome'], $result['reason']]);
        self::assertSame(0, $this->rows('tx_a11y_remediation_item'));
    }

    #[Test]
    public function askRoutesRepairRequestsToThePlanAndInformationQuestionsAsBeforeWithoutAnyAiRequest(): void
    {
        $this->login(self::ADMIN);
        // The container-wired service: no licence in this instance, so AI is unavailable and nothing may be prepared.
        $ask = $this->get(AskAqgService::class);

        $repair = $ask->ask($this->elementContext(), 'Oprav mi túto stránku', 'page');
        self::assertSame(['repair', 'aqg'], [$repair['intent'], $repair['source']]);
        self::assertSame(3, $repair['repair']['counts']['found']);
        self::assertSame([], $repair['repair']['prepareIds'], 'Without a licence nothing is offered for preparation.');
        self::assertFalse($repair['repair']['ai']['ready']);
        self::assertStringContainsString('PRO or Agency licence', $repair['repair']['ai']['reason']);
        self::assertSame(['blocked'], array_values(array_unique(array_column($repair['repair']['items'], 'state'))));

        $information = $ask->ask($this->elementContext(), 'What should I fix first?', 'page');
        self::assertSame('first', $information['intent']);
        self::assertArrayNotHasKey('repair', $information);
        self::assertSame(0, $this->rows('tx_a11y_ai_usage'));
        self::assertSame(0, $this->rows('tx_a11y_remediation_item'));
    }

    /** A backend user with the backend language service every AJAX request has (labels are resolved through it). */
    private function login(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function elementContext(): ToolbarAssistantContext
    {
        return new ToolbarAssistantContext(
            type: ToolbarAssistantContext::TYPE_RECORD,
            siteIdentifier: 'test',
            pageUid: 1,
            languageUid: 0,
            table: 'tt_content',
            recordUid: 10,
            recordTitle: 'AQG 09 - Local cancel 05',
            recordType: 'html',
            pageTitle: 'AQG 09 - Cancel Local Scan',
        );
    }

    private function service(): AskAqgRepairService
    {
        $workflow = $this->workflow();

        return new AskAqgRepairService(
            $this->get(ToolbarAssistantService::class),
            $workflow,
            $this->access,
            $this->get(LocalIssueGuidanceService::class),
            $this->get(IssueLocationService::class),
            $this->get(BackendRecordAccessService::class),
            $this->get(BackendContextService::class),
            $this->get(UriBuilder::class),
        );
    }

    private RemediationAccessService $access;

    private function workflow(): RemediationWorkflowService
    {
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn(true);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturn(new AiProviderConfiguration('openai', 'sk-test', 'gpt-test', 'site'));
        $configuration = $this->createMock(AiConfigurationRepositoryInterface::class);
        $configuration->method('isLinkTextSuggestionsEnabled')->willReturn($this->linkTextEnabled);
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

        return new RemediationWorkflowService(
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
            GeneralUtility::makeInstance(ConnectionPool::class),
        );
    }

    private function rows(string $table): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function bodytext(int $uid): string
    {
        return (string)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->fetchOne('SELECT bodytext FROM tt_content WHERE uid = ' . $uid);
    }
}
