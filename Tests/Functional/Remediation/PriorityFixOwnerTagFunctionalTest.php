<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Controller\RemediationAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAssignedOwnerService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationChangeLogService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemPresenter;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwnerRuleValidator;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationQueueService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetResolver;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * F-08 (Atlas 07·E) against a real database, TYPO3's page permissions and the real Fluid partial: the Overview's
 * "What to fix first" shows the assigned owner of an issue type only where the viewer may open the assigned page,
 * keeps rank, order and the "View affected pages" link, and renders EN and DE labels.
 */
final class PriorityFixOwnerTagFunctionalTest extends AbstractFunctionalTestCase
{
    /** German backend labels are available, as in an installation with the DE language pack. */
    protected array $configurationToUseInTestInstance = ['LANG' => ['availableLocales' => ['de']]];

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        // An editor mounted on the site root who may show pages 1 and 2 but not page 3 (owned by someone else).
        // Module access, but only show permission on the site root: no owner selector, and the route refuses.
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 10, 'pid' => 0, 'title' => 'Editors', 'groupMods' => 'web,web_a11y,content', 'tables_select' => 'pages', 'db_mountpoints' => '1', 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0, 'usergroup' => '10', 'disable' => 0, 'deleted' => 0, 'db_mountpoints' => '1', 'options' => 3]);
        $pages = $pool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_groupid' => 0, 'perms_user' => 31, 'perms_group' => 0, 'perms_everybody' => 1, 'deleted' => 0, 'hidden' => 0]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Service', 'doktype' => 1, 'perms_userid' => 1, 'perms_groupid' => 0, 'perms_user' => 31, 'perms_group' => 0, 'perms_everybody' => 1, 'deleted' => 0, 'hidden' => 0]);
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'Internal', 'doktype' => 1, 'perms_userid' => 1, 'perms_groupid' => 0, 'perms_user' => 31, 'perms_group' => 0, 'perms_everybody' => 0, 'deleted' => 0, 'hidden' => 0]);

        $repository = $this->get(RemediationItemRepository::class);
        $repository->saveOwnerAssignment('test', 2, 'image-alt', 'developer', 1);
        $repository->saveOwnerAssignment('test', 3, 'color-contrast', 'editor', 1);
        $repository->saveOwnerAssignment('test', 1, 'label', 'editor', 1);
        $repository->saveOwnerAssignment('test', 2, 'label', 'developer', 1);
        $repository->saveOwnerAssignment('other', 2, 'link-name', 'developer', 1);
        // A content rule of the same page is not in the frontend list and is never read for it.
        $repository->saveOwnerAssignment('test', 2, 'rte.img_alt_missing', 'editor', 1);

        // Stored frontend scans of the site: a paid one with image-alt and list, a Free Remote Preview with region.
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', ['uid' => 1, 'site_identifier' => 'test', 'job_id' => 'job-1', 'status' => 'completed', 'is_free_preview' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', ['uid' => 2, 'site_identifier' => 'test', 'job_id' => 'job-2', 'status' => 'completed', 'is_free_preview' => 1, 'deleted' => 0]);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', ['uid' => 3, 'site_identifier' => 'other', 'job_id' => 'job-3', 'status' => 'completed', 'is_free_preview' => 0, 'deleted' => 0]);
        foreach ([[1, 'image-alt'], [1, 'list'], [2, 'region'], [3, 'heading-order']] as [$scan, $ruleId]) {
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', ['remote_scan' => $scan, 'remote_scan_page' => 1, 'rule_id' => $ruleId, 'deleted' => 0]);
        }
    }

    #[Test]
    public function onlyRulesOfAStoredPaidFrontendScanOfTheSiteAreAssignable(): void
    {
        $rules = $this->get(RemediationOwnerRuleValidator::class);

        self::assertSame(['image-alt', 'list'], $rules->storedFrontendRuleIds('test', ['image-alt', 'list', 'region', 'heading-order', 'made-up']));
        self::assertTrue($rules->isAssignable('test', 1, 'list', 1));
        self::assertFalse($rules->isAssignable('test', 2, 'list', 1), 'A frontend rule is assigned on the site root only.');
        self::assertFalse($rules->isAssignable('test', 1, 'region', 1), 'Only in a Free Remote Preview result.');
        self::assertFalse($rules->isAssignable('test', 1, 'heading-order', 1), 'Only in a scan of another site.');
        self::assertFalse($rules->isAssignable('test', 1, 'made-up', 1));
    }

    #[Test]
    public function assigningAtTheSiteRootThroughTheRouteShowsTheTagAndUnassignedRemovesIt(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $assigned = $this->ownerRoute(['pageUid' => 1, 'ruleId' => 'list', 'owner' => 'developer']);
        self::assertSame(200, $assigned['status']);
        self::assertSame(['owner' => 'developer', 'label' => 'Developer'], $assigned['body']['tag']);
        self::assertSame('developer', $this->get(RemediationItemRepository::class)->findOwnerAssignment('test', 1, 'list'));
        self::assertSame(['owner' => 'developer', 'pages' => 1], $this->service()->forRules('test', ['list'])['list']);

        // A root assignment next to a different page assignment of the same rule: "Varies by page".
        self::assertSame(['owner' => 'varies', 'label' => 'Varies by page'], $this->ownerRoute(['pageUid' => 1, 'ruleId' => 'image-alt', 'owner' => 'editor'])['body']['tag']);

        $removed = $this->ownerRoute(['pageUid' => 1, 'ruleId' => 'list', 'owner' => 'unassigned']);
        self::assertSame(200, $removed['status']);
        self::assertSame(['owner' => '', 'label' => ''], $removed['body']['tag']);
        self::assertSame([], $this->service()->forRules('test', ['list']));
    }

    #[Test]
    public function theRouteRefusesUnknownRuleIdsFreePreviewRulesAndFrontendRulesBelowTheRoot(): void
    {
        $this->setUpBackendUser(1);

        foreach ([[1, 'made-up'], [1, 'region'], [1, 'heading-order'], [1, str_repeat('x', 300)], [2, 'list']] as [$pageUid, $ruleId]) {
            $refused = $this->ownerRoute(['pageUid' => $pageUid, 'ruleId' => $ruleId, 'owner' => 'developer']);
            self::assertSame([422, 'invalid_input'], [$refused['status'], $refused['body']['code']], $ruleId);
        }
        self::assertNull($this->get(RemediationItemRepository::class)->findOwnerAssignment('test', 1, 'made-up'));
        // The content Page Detail menu keeps working: a rule with a stored content finding on that page.
        $this->insertIssue(['site_identifier' => 'test', 'page_uid' => 2, 'rule_id' => 'rte.empty_link']);
        self::assertSame(200, $this->ownerRoute(['pageUid' => 2, 'ruleId' => 'rte.empty_link', 'owner' => 'editor'])['status']);
    }

    #[Test]
    public function anEditorWithoutEditAccessToTheSiteRootIsRefused(): void
    {
        $this->setUpBackendUser(2);

        $refused = $this->ownerRoute(['pageUid' => 1, 'ruleId' => 'list', 'owner' => 'developer']);

        self::assertSame([403, 'permission_denied'], [$refused['status'], $refused['body']['code']]);
        self::assertNull($this->get(RemediationItemRepository::class)->findOwnerAssignment('test', 1, 'list'));
    }

    #[Test]
    public function withoutThePaidLicenceTheRouteRefuses(): void
    {
        $this->setUpBackendUser(1);

        $refused = $this->ownerRoute(['pageUid' => 1, 'ruleId' => 'list', 'owner' => 'developer'], false);

        self::assertSame([403, 'no_licence'], [$refused['status'], $refused['body']['code']]);
        self::assertNull($this->get(RemediationItemRepository::class)->findOwnerAssignment('test', 1, 'list'));
    }

    #[Test]
    public function theRepositoryReadsOnlyTheRequestedRulesOfOneSite(): void
    {
        $rows = $this->get(RemediationItemRepository::class)->findOwnerAssignmentsForRules('test', ['image-alt', 'link-name', 'color-contrast']);

        self::assertSame([
            ['page_uid' => 2, 'rule_id' => 'image-alt', 'owner' => 'developer'],
            ['page_uid' => 3, 'rule_id' => 'color-contrast', 'owner' => 'editor'],
        ], $rows);
        self::assertSame([], $this->get(RemediationItemRepository::class)->findOwnerAssignmentsForRules('test', []));
    }

    #[Test]
    public function anEditorSeesOnlyAssignmentsOnPagesTheyMayOpen(): void
    {
        $this->setUpBackendUser(2);
        $owners = $this->service()->forRules('test', ['image-alt', 'color-contrast', 'label', 'link-name', 'heading-order']);
        ksort($owners);

        self::assertSame([
            'image-alt' => ['owner' => 'developer', 'pages' => 1],
            'label' => ['owner' => RemediationAssignedOwnerService::VARIES, 'pages' => 2],
        ], $owners);
    }

    #[Test]
    public function anAdministratorAlsoSeesTheAssignmentOnTheRestrictedPage(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(['owner' => 'editor', 'pages' => 1], $this->service()->forRules('test', ['color-contrast'])['color-contrast'] ?? null);
    }

    #[Test]
    public function withoutTheLicenceNobodySeesAnOwner(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame([], $this->service(false)->forRules('test', ['image-alt', 'label']));
    }

    #[Test]
    public function theListRendersTheTagOnlyForAssignedRulesAndKeepsOrderAndLinks(): void
    {
        $this->useBackendLanguage('');
        $xpath = $this->render($this->fixes());

        self::assertSame(['1', '2', '3'], $this->texts($xpath, '//li[contains(@class, "aqg-fix")]/span[contains(@class, "aqg-fix__rank")]'));
        self::assertSame(['Images need alternative text', 'Form fields need labels', 'Text needs more contrast'], $this->texts($xpath, '//li[contains(@class, "aqg-fix")]//h3'));
        self::assertSame(
            ['/overview?remoteRule=image-alt#a11y-remote-top-pages', '/overview?remoteRule=label#a11y-remote-top-pages', '/overview?remoteRule=color-contrast#a11y-remote-top-pages'],
            array_map(static fn (\DOMElement $link): string => $link->getAttribute('href'), iterator_to_array($xpath->query('//a[@data-aqg-rule-filter]')))
        );

        $tags = $xpath->query('//*[@data-aqg-fix-owner]');
        self::assertSame(2, $tags->length, 'No tag for the rule without an assignment.');
        self::assertSame(['Assigned owner: Developer', 'Assigned owner: Varies by page'], $this->texts($xpath, '//*[@data-aqg-fix-owner]'));
        self::assertSame(['Assigned owner:', 'Assigned owner:'], $this->texts($xpath, '//*[@data-aqg-fix-owner]/span[@class="visually-hidden"]'));
        self::assertSame('aqg-fix__owner aqg-fix__owner--varies', $tags->item(1)?->attributes?->getNamedItem('class')?->nodeValue);
        // Visible next to the short fix, outside the closed Details; the suggested owner stays in the Details.
        self::assertSame(0, $xpath->query('//*[@data-aqg-priority-fix-details="true"]//*[@data-aqg-fix-owner]')->length);
        self::assertSame(1, $xpath->query('//li[1]//*[@data-aqg-priority-fix-details="true"][contains(., "Suggested owner")]')->length);
        // No AI tag: no frontend rule has an AI flow.
        self::assertStringNotContainsString('AI', implode(' ', $this->texts($xpath, '//li[contains(@class, "aqg-fix")]/div[contains(@class, "aqg-fix__main")]')));
    }

    #[Test]
    public function theTagIsTranslatedForGermanBackendUsers(): void
    {
        $this->useBackendLanguage('de');
        $fixes = $this->fixes();
        $fixes[0]['assignedOwnerLabel'] = 'Entwicklung';
        $fixes[1]['assignedOwnerLabel'] = 'Je nach Seite unterschiedlich';

        self::assertSame(
            ['Zugewiesene Zuständigkeit: Entwicklung', 'Zugewiesene Zuständigkeit: Je nach Seite unterschiedlich'],
            $this->texts($this->render($fixes), '//*[@data-aqg-fix-owner]')
        );
    }

    private function useBackendLanguage(string $language): void
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 2, 'lang' => $language];
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function theOwnerSelectorSitsInTheClosedDetailsWithALabelAndOnlyWhereEditable(): void
    {
        $this->useBackendLanguage('');
        $fixes = $this->fixes();
        $fixes[0] += ['ownerEditable' => true, 'ownerCurrent' => 'developer', 'ownerPageUid' => 1];
        $fixes[2] += ['ownerEditable' => true, 'ownerCurrent' => 'unassigned', 'ownerPageUid' => 1];
        $xpath = $this->render($fixes);

        $forms = $xpath->query('//form[@data-aqg-fix-owner-form]');
        self::assertSame(2, $forms->length, 'No selector for the fix the person may not assign.');
        foreach ($forms as $form) {
            self::assertInstanceOf(\DOMElement::class, $form);
            self::assertSame(1, $xpath->query('ancestor::*[@data-aqg-priority-fix-details="true"][@hidden]', $form)->length, 'Inside the closed Details.');
            self::assertSame('1', $form->getAttribute('data-page-uid'));
        }
        $select = $xpath->query('//select[@id="aqg-fix-owner-image-alt"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $select);
        self::assertSame('Owner', $this->texts($xpath, '//label[@for="aqg-fix-owner-image-alt"]')[0] ?? '');
        self::assertSame(['editor', 'developer', 'mixed', 'unassigned'], array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('./option', $select))));
        self::assertSame(['developer'], array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('./option[@selected]', $select))));
        self::assertSame(['unassigned'], array_map(static fn (\DOMElement $option): string => $option->getAttribute('value'), iterator_to_array($xpath->query('//select[@id="aqg-fix-owner-color-contrast"]/option[@selected]'))));
        self::assertSame('Developer — template or code', $this->texts($xpath, '//select[@id="aqg-fix-owner-image-alt"]/option[2]')[0] ?? '');
        self::assertSame(1, $xpath->query('//form[@data-rule-id="image-alt"]//button[@data-aqg-fix-owner-save][contains(., "Assign owner")]')->length);
        self::assertSame(1, $xpath->query('//form[@data-rule-id="image-alt"]//*[@role="status"]')->length);
        // An editable fix without an owner keeps an empty, hidden tag slot for the in-place update.
        self::assertSame(1, $xpath->query('//p[@data-aqg-fix-tags="color-contrast"][@hidden]')->length);
        self::assertSame(0, $xpath->query('//p[@data-aqg-fix-tags="color-contrast"]/*')->length);
    }

    /**
     * The real route action with the real access rules; only the licence answer is fixed.
     *
     * @param array<string,mixed> $body
     * @return array{status:int,body:array<string,mixed>}
     */
    private function ownerRoute(array $body, bool $licensed = true): array
    {
        $access = $this->access($licensed);
        $workflow = new RemediationWorkflowService(
            $this->get(RemediationItemRepository::class),
            $this->get(IssueRepository::class),
            $this->get(RemediationTargetResolver::class),
            $access,
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
        $controller = new RemediationAjaxController(
            $workflow,
            $this->get(RemediationQueueService::class),
            $this->get(RemediationItemPresenter::class),
            $access,
            $this->get(RemediationPageSummaryService::class),
            $this->get(IssueRepository::class),
            $this->get(SiteResolutionService::class),
            $this->get(BackendUserService::class),
            $this->get(RemediationChangeLogService::class),
            $this->get(RemediationOwnerRuleValidator::class),
            new RemediationAssignedOwnerService($this->get(RemediationItemRepository::class), $access),
        );
        $request = (new ServerRequest('https://example.test/typo3/ajax/a11y/remediation/owner', 'POST'))
            ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        $response = $controller->ownerAction($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR)];
    }

    private function service(bool $licensed = true): RemediationAssignedOwnerService
    {
        return new RemediationAssignedOwnerService($this->get(RemediationItemRepository::class), $this->access($licensed));
    }

    private function access(bool $licensed): RemediationAccessService
    {
        // The real page permissions (ScopeAccessService); only the licence answer is fixed.
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn($licensed);

        return new RemediationAccessService(
            $this->get(BackendUserService::class),
            $this->get(ScopeAccessService::class),
            $this->get(BackendRecordAccessService::class),
            $this->get(EditorAiAccessService::class),
            $licence,
            $this->get(AccessControlService::class),
            $this->get(\Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService::class),
        );
    }

    /** @return list<array<string,mixed>> */
    private function fixes(): array
    {
        $fix = static fn (int $rank, string $ruleId, string $title, string $owner, string $label): array => [
            'rank' => $rank,
            'ruleId' => $ruleId,
            'displayTitle' => $title,
            'impact' => 'serious',
            'issuesTotal' => 4,
            'affectedPagesTotal' => 2,
            'shortFix' => 'Fix it in the template.',
            'whoShouldFix' => 'developer',
            'whoShouldFixLabel' => 'Developer',
            'affectedPagesUrl' => '/overview?remoteRule=' . $ruleId . '#a11y-remote-top-pages',
            'assignedOwner' => $owner,
            'assignedOwnerLabel' => $label,
        ];

        return [
            $fix(1, 'image-alt', 'Images need alternative text', 'developer', 'Developer'),
            $fix(2, 'label', 'Form fields need labels', 'varies', 'Varies by page'),
            $fix(3, 'color-contrast', 'Text needs more contrast', '', ''),
        ];
    }

    /** @param list<array<string,mixed>> $fixes */
    private function render(array $fixes): \DOMXPath
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => 'Overview/RemotePanel', 'arguments' => [
            'remoteScan' => ['uid' => 5, 'job_id' => 'job-5', 'issues_total' => 12, 'finished_at' => 1789200000],
            'remotePriorityFixes' => $fixes,
            'remotePriorityFixesVisible' => $fixes,
            'remotePriorityFixesMoreCount' => 0,
            'freePreview' => ['isFree' => false],
        ]]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /** @return list<string> */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) as $node) {
            $texts[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }
}
