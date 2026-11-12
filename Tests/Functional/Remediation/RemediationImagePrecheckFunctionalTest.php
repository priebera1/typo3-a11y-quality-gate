<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiImageResolver;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRecordContext;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
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
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Page Detail "Fix with AI" on <img> findings in Plain HTML / rich text (the reported "AQG could not read this image").
 * Real FAL storage on disk, real rule output, real workflow: an image AQG cannot send is refused while preparing, with
 * its exact reason, before an AI fixes item holds the finding and before any provider request; a readable local image
 * (relative "fileadmin/…" as well as "/fileadmin/…") is prepared as before. The reason never tells a user without read
 * access whether a file exists.
 */
final class RemediationImagePrecheckFunctionalTest extends AbstractFunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR_WITHOUT_FILE_MOUNT = 3;

    private const HTML = '<p>Gallery</p>'
        . '<img src="fileadmin/local-cancel-1.jpg">'
        . '<img src="fileadmin/aqg/photo.png">'
        . '<img src="https://example.org/remote.jpg">'
        . '<img src="fileadmin/aqg/fake.jpg">'
        . '<img src="/fileadmin/aqg/photo.png" title="absolute">'
        . '<img src="typo3temp/outside.png">';

    /** @var array<string,int> finding uid per src */
    private array $findings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");
        @mkdir($this->instancePath . '/fileadmin/aqg', 0777, true);
        @mkdir($this->instancePath . '/typo3temp', 0777, true);
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 30, 110, 200));
        imagepng($image, $this->instancePath . '/fileadmin/aqg/photo.png');
        imagepng($image, $this->instancePath . '/typo3temp/outside.png');
        file_put_contents($this->instancePath . '/fileadmin/aqg/fake.jpg', 'not an image');

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Cancel local scan', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', ['uid' => 1, 'pid' => 0, 'name' => 'fileadmin', 'driver' => 'Local', 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1, 'is_online' => 1,
            'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field><field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>']);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'html', 'header' => 'Screenshot reproduction', 'bodytext' => self::HTML, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::ADMIN, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 11, 'title' => 'Editors without file mount', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'tt_content', 'tables_modify' => 'tt_content', 'non_exclude_fields' => 'tt_content:bodytext', 'explicit_allowdeny' => 'tt_content:CType:html']);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => self::EDITOR_WITHOUT_FILE_MOUNT, 'username' => 'editor', 'admin' => 0, 'usergroup' => '11', 'disable' => 0, 'deleted' => 0]);

        // The findings exactly as the content scan stores them (same rule, context path and snippet).
        $rule = $this->get(RuleRegistry::class)->getById('rte.img_alt_missing');
        self::assertNotNull($rule);
        foreach ($rule->check(new CheckContext(
            siteIdentifier: 'test',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 10,
            sourceField: 'bodytext',
            content: self::HTML,
            cType: 'html',
            contextPath: 'Page:1 > tt_content:10 > bodytext',
        )) as $violation) {
            preg_match('/src="([^"]*)"/', $violation->contextSnippet, $src);
            $key = ($src[1] ?? '') . (str_contains($violation->contextSnippet, 'absolute') ? '#absolute' : '');
            $this->findings[$key] = $this->insertIssue([
                'source_uid' => 10, 'source_type' => 'rte', 'rule_id' => 'rte.img_alt_missing',
                'context_path' => $violation->contextPath, 'context_snippet' => $violation->contextSnippet,
            ]);
        }
        self::assertCount(6, $this->findings, json_encode(array_keys($this->findings)) ?: '');
    }

    #[Test]
    public function imagesAqgCannotSendAreRefusedWithTheirReasonBeforeAnyItemOrRequest(): void
    {
        $this->setUpBackendUser(self::ADMIN);
        $workflow = $this->workflow();

        foreach ([
            'fileadmin/local-cancel-1.jpg' => EditorAiImageResolver::MISSING,
            'https://example.org/remote.jpg' => EditorAiImageResolver::EXTERNAL,
            'fileadmin/aqg/fake.jpg' => EditorAiImageResolver::NOT_AN_IMAGE,
            'typo3temp/outside.png' => EditorAiImageResolver::NOT_IN_STORAGE,
        ] as $src => $reason) {
            $result = $workflow->prepare([$this->findings[$src]], RemediationAccessService::ORIGIN_EDITOR_REQUEST);
            self::assertSame(['itemUid' => 0, 'outcome' => 'skipped', 'reason' => $reason], array_diff_key($result['items'][0], ['findingUid' => 0]), $src);
        }
        self::assertSame(0, $this->itemCount(), 'A refused image never leaves a (Failed) AI fixes item behind.');
        self::assertSame(0, (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_ai_usage')
            ->fetchOne('SELECT COUNT(*) FROM tx_a11y_ai_usage'), 'Nothing was sent to a provider.');
    }

    #[Test]
    public function readableLocalImagesArePreparedWithRelativeAndAbsolutePaths(): void
    {
        $this->setUpBackendUser(self::ADMIN);
        $workflow = $this->workflow();

        foreach (['fileadmin/aqg/photo.png', '/fileadmin/aqg/photo.png#absolute'] as $key) {
            $result = $workflow->prepare([$this->findings[$key]], RemediationAccessService::ORIGIN_EDITOR_REQUEST);
            self::assertSame('queued', $result['items'][0]['outcome'], $key . ' ' . json_encode($result));
        }
        self::assertSame(2, $this->itemCount());
    }

    #[Test]
    public function aReadableImageIsActuallyPreparedForTheProvider(): void
    {
        $this->setUpBackendUser(self::ADMIN);
        $resolver = $this->get(EditorAiImageResolver::class);
        $context = $this->recordContext();

        $dataUrl = $resolver->imageDataUrl(['src' => 'fileadmin/aqg/photo.png'], $context, self::HTML);
        self::assertIsString($dataUrl);
        self::assertStringStartsWith('data:image/', $dataUrl);
        self::assertSame(EditorAiImageResolver::READABLE, $resolver->failureReason(['src' => 'fileadmin/aqg/photo.png'], $context, self::HTML));
        self::assertNull($resolver->imageDataUrl(['src' => 'fileadmin/local-cancel-1.jpg'], $context, self::HTML));
        self::assertSame(EditorAiImageResolver::MISSING, $resolver->failureReason(['src' => 'fileadmin/local-cancel-1.jpg'], $context, self::HTML));
    }

    #[Test]
    public function withoutReadAccessTheAnswerDoesNotRevealWhetherAFileExists(): void
    {
        // A backend request, as in Page Detail: TYPO3 then evaluates the editor's file mounts and file permissions
        // (StoragePermissionsAspect) on every storage it initializes.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.test/typo3/'))->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $this->setUpBackendUser(self::EDITOR_WITHOUT_FILE_MOUNT);
        $resolver = $this->get(EditorAiImageResolver::class);
        $context = $this->recordContext();

        // An image the saved content does not show (typed in this session): TYPO3's normal read permission applies, and
        // a missing and an existing file answer alike.
        self::assertSame(EditorAiImageResolver::NO_ACCESS, $resolver->availability(['src' => 'fileadmin/aqg/other-missing.png'], $context, self::HTML));
        self::assertSame(EditorAiImageResolver::NO_ACCESS, $resolver->availability(['src' => 'fileadmin/aqg/photo.png'], $context, '<p>no image</p>'));
        // A probing TYPO3 file uid or processed path the saved content does not show answers like a refused file.
        self::assertSame(EditorAiImageResolver::NO_ACCESS, $resolver->availability(['src' => 'fileadmin/aqg/photo.png', 'data-htmlarea-file-uid' => '99999'], $context, '<p>no image</p>'));
        self::assertSame(EditorAiImageResolver::NO_ACCESS, $resolver->availability(['src' => 'fileadmin/_processed_/a/b/csm_unknown_0123456789.png'], $context, '<p>no image</p>'));
        // The image the saved record already shows in a public storage stays readable for the editor of that record
        // (the existing relaxation), so its own answer is precise.
        self::assertSame(EditorAiImageResolver::READABLE, $resolver->availability(['src' => 'fileadmin/aqg/photo.png'], $context, self::HTML));
        self::assertSame(EditorAiImageResolver::MISSING, $resolver->availability(['src' => 'fileadmin/local-cancel-1.jpg'], $context, self::HTML));
    }

    private function recordContext(): EditorAiRecordContext
    {
        return new EditorAiRecordContext('tt_content', 10, 'bodytext', 1, 'test', 0, 'en_US', 'English', 'en_US', 0, false, 'html');
    }

    private function itemCount(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item')
            ->fetchOne('SELECT COUNT(*) FROM tx_a11y_remediation_item');
    }

    private function workflow(): RemediationWorkflowService
    {
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn(true);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturn(new AiProviderConfiguration('openai', 'sk-test', 'gpt-test', 'site'));
        $editorAiAccess = new EditorAiAccessService(
            $licence,
            $resolver,
            $this->get(AiSiteSettingsService::class),
            $this->createMock(AiConfigurationRepositoryInterface::class),
            $this->get(BackendUserService::class),
        );
        $access = new RemediationAccessService(
            $this->get(BackendUserService::class),
            $this->get(ScopeAccessService::class),
            $this->get(BackendRecordAccessService::class),
            $editorAiAccess,
            $licence,
            $this->get(AccessControlService::class),
            $this->get(\Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService::class),
        );

        return new RemediationWorkflowService(
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
    }
}
