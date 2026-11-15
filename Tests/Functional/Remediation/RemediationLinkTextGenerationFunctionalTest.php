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
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskExecutorInterface;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
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
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AI Fixes for link text, end to end through the real workflow (prepare → generate) with a deterministic provider
 * double instead of a paid provider: the item targets exactly the finding's record and field, nothing is written to
 * the record before a person approves, a decline keeps the model's reason as the item explanation, and a value AQG's
 * own rule still reports is never offered for review. Fixtures: the live typo3test14 element 939 (finding 75325) and a
 * link with enough context.
 */
final class RemediationLinkTextGenerationFunctionalTest extends AbstractFunctionalTestCase
{
    private const ADMIN = 1;
    private const OTHER_MOUNT_EDITOR = 4;
    /** tt_content 939 on typo3test14 ("AQG 02 – Local Mixed"), byte for byte as saved. */
    private const LIVE = "<h2>\n    Spravny nadpis\n</h2>\n<p>\n    Toto je mix stranka.\n</p>\n<p>\n    <a href=\"/detail\">Viac o sluzbe</a> <a href=\"/info\">read more</a>\n</p>";
    private const CONTACT = '<p>Our bakery in Lindenhof is open Monday to Saturday from 6:30 to 18:00. Directions, parking and our phone number: <a href="/contact">click here</a>.</p>';

    /** @var array<int,int> tt_content uid => finding uid */
    private array $findings = [];
    /** @var array<string,string> */
    private array $answer = [];
    /** @var list<AiStructuredRequest> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'AQG 02 - Local Mixed', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Other page', 'doktype' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 939, 'pid' => 1, 'CType' => 'text', 'header' => 'Mixed', 'bodytext' => self::LIVE, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 940, 'pid' => 1, 'CType' => 'text', 'header' => 'Contact', 'bodytext' => self::CONTACT, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::ADMIN, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 12, 'title' => 'Other mount', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '2',
            'tables_select' => 'tt_content,pages', 'tables_modify' => 'tt_content', 'non_exclude_fields' => 'tt_content:bodytext', 'explicit_allowdeny' => 'tt_content:CType:text',
            'TSconfig' => "options.a11y_quality_gate.ai.editor = 1\noptions.a11y_quality_gate.ai.bulk = 1"]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::OTHER_MOUNT_EDITOR, 'username' => 'other', 'admin' => 0, 'usergroup' => '12', 'disable' => 0, 'deleted' => 0]);

        // The findings exactly as the content scan stores them (rule, context path, snippet).
        $rule = $this->get(RuleRegistry::class)->getById('rte.non_descriptive_link');
        self::assertNotNull($rule);
        foreach ([939 => self::LIVE, 940 => self::CONTACT] as $uid => $html) {
            $violations = $rule->check(new CheckContext(
                siteIdentifier: 'test', pageUid: 1, sourceLangUid: 0, sourceTable: 'tt_content', sourceUid: $uid, sourceField: 'bodytext',
                content: $html, cType: 'text', contextPath: 'Page:1 > tt_content:' . $uid . ' > bodytext',
            ));
            self::assertCount(1, $violations, 'Only the generic link is reported in element ' . $uid);
            $this->findings[$uid] = $this->insertIssue([
                'source_uid' => $uid, 'source_type' => 'rte', 'rule_id' => 'rte.non_descriptive_link', 'severity' => 2,
                'message' => $violations[0]->message, 'context_path' => $violations[0]->contextPath, 'context_snippet' => $violations[0]->contextSnippet,
            ]);
        }
        self::assertSame('p[2] > a[2]', $this->issue($this->findings[939])['context_path'], 'The stored path of finding 75325.');
    }

    #[Test]
    public function aLinkWithEnoughContextBecomesAPendingSuggestionForExactlyItsRecordAndFieldWithoutAnyWrite(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions, parking and phone number', 'reason' => 'The paragraph says what the contact page offers.'];

        $item = $this->prepareAndGenerate(940);

        self::assertSame(RemediationStatus::PENDING_REVIEW, $item['status']);
        self::assertSame(['tt_content', 940, 'bodytext'], [$item['target_table'], (int)$item['target_uid'], $item['target_field']]);
        self::assertSame('click here', $item['original_value']);
        self::assertSame('Directions, parking and phone number', $item['proposed_value']);
        self::assertSame('', $item['status_reason'], 'AQG\'s own check confirms the value fixes the finding.');
        self::assertCount(1, $this->requests);
        self::assertSame(AiTask::LINK_TEXT, $this->requests[0]->task);
        self::assertStringContainsString('/contact', $this->requests[0]->inputText);
        self::assertStringNotContainsString('Viac o sluzbe', $this->requests[0]->inputText, 'Nothing of another element is sent.');
        self::assertSame(self::CONTACT, $this->bodytext(940), 'Nothing is written before a person approves.');
        self::assertSame(self::LIVE, $this->bodytext(939));
    }

    #[Test]
    public function theLiveFindingIsDeclinedWithTheModelReasonKeptForTheEditor(): void
    {
        $this->login(self::ADMIN);
        $reason = "The link target is only '/info', so the destination's topic cannot be determined safely.";
        $this->answer = ['status' => 'needs_review', 'value' => '', 'reason' => $reason];

        $item = $this->prepareAndGenerate(939);

        self::assertSame(RemediationStatus::NO_SUGGESTION, $item['status']);
        self::assertSame('needs_review', $item['status_reason']);
        self::assertSame($reason, $item['explanation']);
        self::assertSame('read more', $item['original_value']);
        self::assertSame('', $item['proposed_value']);
        $descriptor = json_decode((string)$item['target_descriptor'], true);
        self::assertSame(['p[2] > a[2]', 1, ['href' => '/info']], [$descriptor['contextPath'], $descriptor['occurrence'], $descriptor['attributes']]);
        self::assertSame(self::LIVE, $this->bodytext(939));
    }

    #[Test]
    public function aGenericOrUnsafeValueIsNeverOfferedForReviewAndKeepsNoModelText(): void
    {
        $this->login(self::ADMIN);
        foreach (['Click here', '<b>Info</b>', 'https://example.test/info'] as $index => $value) {
            $this->answer = ['status' => 'suggestion', 'value' => $value, 'reason' => 'Model text that must not be kept.'];
            $item = $index === 0 ? $this->prepareAndGenerate(939) : $this->regenerate($item);

            self::assertSame(RemediationStatus::NO_SUGGESTION, $item['status'], $value);
            self::assertSame('invalid_response', $item['status_reason'], $value);
            self::assertSame('', $item['proposed_value'], $value);
            self::assertSame('', $item['explanation'], $value);
        }
        self::assertSame(self::LIVE, $this->bodytext(939));
    }

    #[Test]
    public function aUserWhoMayNotEditTheRecordCannotPrepareItAndNoProviderIsAsked(): void
    {
        $this->login(self::OTHER_MOUNT_EDITOR);
        $this->answer = ['status' => 'suggestion', 'value' => 'Never asked', 'reason' => 'r'];

        $result = $this->workflow()->prepare([$this->findings[940]], RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0];

        self::assertNotSame('queued', $result['outcome']);
        self::assertSame(0, $this->rows('tx_a11y_remediation_item'));
        self::assertSame([], $this->requests);
    }

    /** @return array<string,mixed> the item row after one generate() */
    private function prepareAndGenerate(int $contentUid): array
    {
        $workflow = $this->workflow();
        $prepared = $workflow->prepare([$this->findings[$contentUid]], RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0];
        self::assertSame('queued', $prepared['outcome'], (string)($prepared['reason'] ?? ''));
        $item = $this->get(RemediationItemRepository::class)->findByUid((int)$prepared['itemUid']);
        self::assertSame(RemediationStatus::PREPARING, $item['status']);
        $workflow->generate((int)$item['uid'], (int)$item['row_version']);

        return $this->get(RemediationItemRepository::class)->findByUid((int)$item['uid']);
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function regenerate(array $item): array
    {
        $this->workflow()->generate((int)$item['uid'], (int)$item['row_version']);

        return $this->get(RemediationItemRepository::class)->findByUid((int)$item['uid']);
    }

    private function login(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function workflow(): RemediationWorkflowService
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

        // The provider: a deterministic double. Every request is recorded; nothing leaves the test.
        $executor = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $executor->method('supports')->willReturn(true);
        $executor->method('execute')->willReturnCallback(function (AiStructuredRequest $request): AiStructuredResponse {
            $this->requests[] = $request;
            return new AiStructuredResponse($this->answer, new AiUsage(800, 60, true), 'anthropic', 'claude-haiku-5-5');
        });
        $runner = new AiStructuredTaskRunner($licence, $settings, $this->get(AiRateLimiter::class), $resolver,
            new AiUsageRecorder($this->get(AiUsageRepository::class), new AiUsageMeter(), $users), [$executor]);
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
            $this->get(RemediationItemRepository::class), $this->get(IssueRepository::class), $this->get(RemediationTargetResolver::class),
            $access, $this->get(ReferenceAltAiService::class), $editorAi, $this->get(EditorAiValueValidator::class),
            $this->get(ImageAltTextValidator::class), $this->get(ImageRemediationServiceInterface::class), $this->get(ImageFindingContextResolver::class),
            $this->get(ImageFindingVersionTokenService::class), $this->get(RuleMetadataPresentationServiceInterface::class),
            GeneralUtility::makeInstance(ConnectionPool::class),
        );
    }

    /** @return array<string,mixed> */
    private function issue(int $uid): array
    {
        return (array)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['*'], 'tx_a11y_issue', ['uid' => $uid])->fetchAssociative();
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
