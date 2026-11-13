<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Media\MediaAiUsagePrivacy;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * D-08: the image input switch of every site that uses a file applies to its file default, whatever site's AI
 * settings were chosen. Site "open" allows images, site "closed" forbids them; page 3 belongs to no site.
 */
final class MediaAiUsagePrivacyFunctionalTest extends AbstractFunctionalTestCase
{
    private ConnectionPool $pool;
    private int $reference = 100;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['open' => 1, 'closed' => 2] as $identifier => $root) {
            $dir = $this->instancePath . '/typo3conf/sites/' . $identifier;
            @mkdir($dir, 0777, true);
            file_put_contents($dir . '/config.yaml', "rootPageId: $root\nbase: 'https://$identifier.example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n  - languageId: 1\n    title: Deutsch\n    locale: de_DE.UTF-8\n    base: /de/\n");
        }
        $this->pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 1], [2, 0, 1], [3, 0, 0], [11, 1, 0], [21, 2, 0]] as [$uid, $pid, $root]) {
            $this->pool->getConnectionForTable('pages')->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => $root ? 1 : 254, 'is_siteroot' => $root, 'deleted' => 0, 'hidden' => 0]);
        }
        $this->pool->getConnectionForTable('tx_a11y_ai_site_setting')->insert('tx_a11y_ai_site_setting', ['site_identifier' => 'closed', 'active_provider' => 'openai', 'allow_image_input' => 0, 'allow_page_title' => 1]);
        $this->pool->getConnectionForTable('tx_a11y_ai_site_setting')->insert('tx_a11y_ai_site_setting', ['site_identifier' => 'open', 'active_provider' => 'openai', 'allow_image_input' => 1, 'allow_page_title' => 1]);
        foreach ([[10, 11], [20, 21], [30, 3]] as [$uid, $pid]) {
            $this->pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'CType' => 'image', 'header' => 'Content ' . $uid, 'deleted' => 0, 'hidden' => 0]);
        }
    }

    #[Test]
    public function aFileUsedOnlyWhereImagesAreAllowedMayBeSent(): void
    {
        $this->use(1, 10, 11);
        self::assertSame(MediaAiUsagePrivacy::ALLOWED, $this->privacy()->decide(1));
        $this->privacy()->assertImageInputAllowed(1);
    }

    #[Test]
    public function aFileUsedOnlyOnASiteThatForbidsImagesIsNeverSent(): void
    {
        $this->use(2, 20, 21);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(2));
        $this->assertRefused('privacy_images_usage', 2);
    }

    #[Test]
    public function conflictingSitesBlockTheFileDefault(): void
    {
        $this->use(3, 10, 11);
        $this->use(3, 20, 21);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(3));
    }

    #[Test]
    public function aFileWithoutUsesDependsOnTheChosenSiteOnly(): void
    {
        self::assertSame(MediaAiUsagePrivacy::ALLOWED, $this->privacy()->decide(4));
    }

    #[Test]
    public function aUseOutsideEverySiteIsAnUnknownDecisionAndRefused(): void
    {
        $this->use(5, 30, 3);
        self::assertSame(MediaAiUsagePrivacy::UNKNOWN, $this->privacy()->decide(5));
        $this->assertRefused('privacy_usage_unknown', 5);
        // A record at the root (no page) cannot be placed in a site either.
        $this->use(15, 99, 0, ['tablenames' => 'sys_category']);
        self::assertSame(MediaAiUsagePrivacy::UNKNOWN, $this->privacy()->decide(15));
    }

    #[Test]
    public function hiddenUsesCountAndDeletedReferencesDoNot(): void
    {
        $this->use(6, 20, 21, ['hidden' => 1]);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(6));

        $this->use(7, 10, 11);
        $this->use(7, 20, 21, ['deleted' => 1]);
        self::assertSame(MediaAiUsagePrivacy::ALLOWED, $this->privacy()->decide(7));
    }

    #[Test]
    public function workspaceAndTranslatedUsesCount(): void
    {
        $this->use(8, 10, 11);
        $this->use(8, 20, 21, ['t3ver_wsid' => 1, 't3ver_state' => 1]);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(8), 'A draft use in a workspace goes live on publish.');

        $this->use(9, 20, 21, ['sys_language_uid' => 1]);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(9));
    }

    #[Test]
    public function imagesInRichTextCountThroughTheReferenceIndex(): void
    {
        $this->pool->getConnectionForTable('sys_refindex')->insert('sys_refindex', ['hash' => md5('x10'), 'tablename' => 'tt_content', 'recuid' => 20, 'field' => 'bodytext', 'ref_table' => 'sys_file', 'ref_uid' => 10, 'workspace' => 0]);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(10));
    }

    #[Test]
    public function usesWithoutAPageOnTheReferenceArePlacedThroughTheirRecordOrPage(): void
    {
        // Reference without pid: the parent record's page decides (content 20 is on the "closed" site).
        $this->use(11, 20, 0);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(11));
        // Page media: the page itself.
        $this->use(12, 21, 0, ['tablenames' => 'pages', 'fieldname' => 'media']);
        self::assertSame(MediaAiUsagePrivacy::BLOCKED, $this->privacy()->decide(12));
        $this->use(13, 11, 0, ['tablenames' => 'pages', 'fieldname' => 'media']);
        self::assertSame(MediaAiUsagePrivacy::ALLOWED, $this->privacy()->decide(13));
        // A rich-text use in a table AQG cannot place is unknown, never "allowed".
        $this->pool->getConnectionForTable('sys_refindex')->insert('sys_refindex', ['hash' => md5('x14'), 'tablename' => 'tx_unknown_table', 'recuid' => 1, 'field' => 'bodytext', 'ref_table' => 'sys_file', 'ref_uid' => 14, 'workspace' => 0]);
        self::assertSame(MediaAiUsagePrivacy::UNKNOWN, $this->privacy()->decide(14));
    }

    /** @param array<string,mixed> $overrides */
    private function use(int $file, int $content, int $page, array $overrides = []): void
    {
        $this->pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', $overrides + [
            'uid' => ++$this->reference, 'pid' => $page, 'uid_local' => $file, 'uid_foreign' => $content, 'tablenames' => 'tt_content',
            'fieldname' => 'image', 'deleted' => 0, 'hidden' => 0, 'sys_language_uid' => 0,
        ]);
    }

    private function privacy(): MediaAiUsagePrivacy
    {
        return $this->get(MediaAiUsagePrivacy::class);
    }

    private function assertRefused(string $code, int $file): void
    {
        try {
            $this->privacy()->assertImageInputAllowed($file);
            self::fail('The image of file ' . $file . ' would have been sent.');
        } catch (EditorAiException $exception) {
            self::assertSame($code, $exception->safeCode);
            self::assertStringNotContainsString('closed', $exception->getMessage(), 'The refusal never names another site.');
        }
    }
}
