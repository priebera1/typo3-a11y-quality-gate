<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Controller\MediaAiAjaxController;
use Priebera\A11yQualityGate\Ai\Media\FileMetadataRecordResolver;
use Priebera\A11yQualityGate\Ai\Media\MediaAiMetadataEditLink;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The real records behind AI media: one metadata record per file and language, no AQG write path into
 * sys_file_metadata (UX Atlas open decision #1 is not taken: TYPO3 Save in the metadata form stores the values), and
 * image uses that belong to exactly one content element.
 */
final class MediaAiWritePathFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Water tariffs', 'doktype' => 1, 'perms_userid' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'fileadmin', 'driver' => 'Local', 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1, 'is_online' => 1,
            'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field><field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>',
        ]);
        @mkdir($this->instancePath . '/fileadmin', 0777, true);
        file_put_contents($this->instancePath . '/fileadmin/chart.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $pool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => 5, 'pid' => 0, 'storage' => 1, 'identifier' => '/chart.png', 'identifier_hash' => sha1('/chart.png'), 'folder_hash' => sha1('/'),
            'name' => 'chart.png', 'extension' => 'png', 'mime_type' => 'image/png', 'type' => 2, 'size' => 70, 'missing' => 0,
        ]);
        $metadata = $pool->getConnectionForTable('sys_file_metadata');
        $metadata->insert('sys_file_metadata', ['uid' => 50, 'pid' => 0, 'file' => 5, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'alternative' => 'English default', 'title' => '', 'description' => '']);
        $metadata->insert('sys_file_metadata', ['uid' => 51, 'pid' => 0, 'file' => 5, 'sys_language_uid' => 1, 'l10n_parent' => 50, 'alternative' => '', 'title' => '', 'description' => '']);
        $content = $pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'image', 'header' => 'Tariffs', 'deleted' => 0, 'hidden' => 0]);
        $content->insert('tt_content', ['uid' => 11, 'pid' => 1, 'CType' => 'image', 'header' => 'Electricity', 'deleted' => 0, 'hidden' => 0]);
        $references = $pool->getConnectionForTable('sys_file_reference');
        $references->insert('sys_file_reference', ['uid' => 20, 'pid' => 1, 'uid_local' => 5, 'uid_foreign' => 10, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => null, 'tstamp' => 100, 'deleted' => 0, 'hidden' => 0]);
        $references->insert('sys_file_reference', ['uid' => 21, 'pid' => 1, 'uid_local' => 5, 'uid_foreign' => 11, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => '', 'tstamp' => 100, 'deleted' => 0, 'hidden' => 0]);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function eachLanguageResolvesToItsOwnMetadataRecordOnly(): void
    {
        $resolver = $this->get(FileMetadataRecordResolver::class);

        self::assertSame(50, $resolver->find(5, 0)['uid']);
        self::assertSame('English default', $resolver->find(5, 0)['values']['alternative']);
        self::assertSame(51, $resolver->find(5, 1)['uid']);
        self::assertSame('', $resolver->find(5, 1)['values']['alternative'], 'A translation never shows the default value as its own.');
        self::assertNull($resolver->find(5, 2), 'A missing translation is not replaced by the default record.');
        self::assertSame([0, 1], $resolver->languages(5));
    }

    #[Test]
    public function anAmbiguousDuplicateTranslationIsNeverGuessed(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_metadata')
            ->insert('sys_file_metadata', ['uid' => 52, 'pid' => 0, 'file' => 5, 'sys_language_uid' => 1, 'l10n_parent' => 50, 'alternative' => 'Duplicate']);

        self::assertNull($this->get(FileMetadataRecordResolver::class)->find(5, 1));
    }

    #[Test]
    public function theUsesSummarySeparatesInheritedAndOwnText(): void
    {
        $uses = $this->get(FileMetadataRecordResolver::class)->uses(5, 0);

        self::assertSame(2, $uses['total']);
        self::assertSame(1, $uses['inheriting']);
        self::assertSame(1, $uses['overriding']);
        self::assertSame([20], array_column($uses['inheritingItems'], 'referenceUid'));
    }

    #[Test]
    public function noRegisteredBackendRouteWritesFileMetadata(): void
    {
        $routes = iterator_to_array($this->get(Router::class)->getRoutes());
        $media = array_filter(array_keys($routes), static fn (string $name): bool => str_contains($name, 'a11y_ai_media_'));

        self::assertNotSame([], $media, 'The read-only AI media routes are registered.');
        self::assertArrayNotHasKey('ajax_a11y_ai_media_apply_metadata', $routes);
        self::assertArrayNotHasKey('a11y_ai_media_apply_metadata', $routes);
        foreach ($routes as $name => $route) {
            self::assertStringNotContainsString('apply-metadata', $route->getPath(), $name . ' writes file metadata.');
        }
        self::assertFalse(method_exists(MediaAiAjaxController::class, 'applyMetadataAction'));
    }

    #[Test]
    public function theRowEntryLeadsIntoTypo3sMetadataFormOfTheExistingRecord(): void
    {
        $link = $this->get(MediaAiMetadataEditLink::class);
        $url = urldecode($link->url(5, '/typo3/module/file/list?id=1:/'));

        self::assertStringContainsString('edit[sys_file_metadata][50]=edit', $url, 'The default-language record, never a translation.');
        self::assertStringContainsString('returnUrl=/typo3/module/file/list?id=1:/', $url);
        self::assertSame('', $link->url(99), 'A file without a metadata record gets no link, and no record is created.');
        self::assertSame(0, (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_metadata')
            ->count('*', 'sys_file_metadata', ['file' => 99]));
    }

    #[Test]
    public function aReferenceOfAnotherContentElementIsRejectedBeforeAnyAiRequest(): void
    {
        try {
            $this->get(ReferenceAltAiService::class)->suggest([
                'parentTable' => 'tt_content', 'parentUid' => 10, 'fieldName' => 'image', 'referenceUid' => 21, 'fileUid' => 0, 'currentAlt' => '', 'mode' => 'suggest',
            ]);
            self::fail('A reference of another content element was accepted.');
        } catch (EditorAiException $exception) {
            self::assertSame('reference_mismatch', $exception->safeCode);
        }
        foreach ([['parentTable' => 'pages', 'parentUid' => 1, 'fieldName' => 'media'], ['parentTable' => 'tt_content', 'parentUid' => 10, 'fieldName' => 'bodytext'], ['parentTable' => 'tt_content', 'parentUid' => 0, 'fieldName' => 'image']] as $target) {
            try {
                $this->get(ReferenceAltAiService::class)->suggest($target + ['referenceUid' => 20, 'fileUid' => 0, 'currentAlt' => '', 'mode' => 'suggest']);
                self::fail('An unsupported target was accepted.');
            } catch (EditorAiException $exception) {
                self::assertContains($exception->safeCode, ['invalid_input', 'record_unsaved']);
            }
        }
    }

    #[Test]
    public function verificationUsesAqgsStructuredChecksOfTheSavedReference(): void
    {
        $service = $this->get(ReferenceAltAiService::class);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');

        $items = static fn (int $suggestedAt): array => [['referenceUid' => 20, 'suggestedAt' => $suggestedAt], ['referenceUid' => 21, 'suggestedAt' => 50]];
        // Reference 20 inherits "English default"; reference 21 belongs to another element.
        self::assertSame(
            [['referenceUid' => 20, 'status' => 'verified'], ['referenceUid' => 21, 'status' => 'not_saved']],
            $service->verify('tt_content', 10, 'image', $items(50))['items'],
        );
        self::assertSame('not_saved', $service->verify('tt_content', 10, 'image', $items(200))['items'][0]['status'], 'Older than its suggestion: not saved.');

        $connection->update('sys_file_reference', ['alternative' => str_repeat('Long description ', 10)], ['uid' => 20]);
        self::assertSame('still_reported', $service->verify('tt_content', 10, 'image', $items(50))['items'][0]['status'], 'A quality finding (over 120 characters) is still reported.');
        $connection->update('sys_file_reference', ['alternative' => ''], ['uid' => 20]);
        self::assertSame('still_reported', $service->verify('tt_content', 10, 'image', $items(50))['items'][0]['status'], 'An empty own text hides the default.');
    }
}
