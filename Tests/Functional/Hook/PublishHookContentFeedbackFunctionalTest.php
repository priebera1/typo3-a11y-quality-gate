<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Hook;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Save feedback after a real DataHandler run (the registered PublishHook, a real content scan).
 *
 * Regression guard: the Page module's "+ Content" below an element saves the new element with a negative pid
 * ("after element |pid|"). The hook took that pid for the page, dropped the element and gave no feedback, while the
 * same element saved with a positive pid (or edited later) got it.
 */
final class PublishHookContentFeedbackFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';
    private const PAGE = 1;
    private const EXISTING = 10;
    private const QUEUE = 'core.template.flashMessages';

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Fixture', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::EXISTING, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Existing', 'bodytext' => '<p>Fine.</p>',
            'sorting' => 256, 'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, self::PAGE, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function aNewElementInsertedAfterAnotherGetsFeedbackForItsPage(): void
    {
        $this->save(['NEW1' => ['pid' => -self::EXISTING, 'CType' => 'text', 'header' => 'Inserted', 'bodytext' => '<p><img src="photo.png"></p>']]);

        $messages = $this->contentFeedback();
        self::assertCount(1, $messages, 'one notification for the inserted element');
        self::assertStringContainsString('This content element has 1 accessibility issue', $messages[0]);
    }

    #[Test]
    public function aNewElementAtTheTopOfThePageStillGetsFeedback(): void
    {
        $this->save(['NEW1' => ['pid' => self::PAGE, 'CType' => 'text', 'header' => 'First', 'bodytext' => '<p><img src="photo.png"></p>']]);

        self::assertCount(1, $this->contentFeedback());
    }

    #[Test]
    public function anEditedExistingElementStillGetsFeedback(): void
    {
        $this->save([self::EXISTING => ['bodytext' => '<p><img src="photo.png"></p>']]);

        self::assertCount(1, $this->contentFeedback());
    }

    #[Test]
    public function anElementWithoutIssuesGetsNoNotification(): void
    {
        $this->save(['NEW1' => ['pid' => -self::EXISTING, 'CType' => 'text', 'header' => 'Clean', 'bodytext' => '<p>Plain text.</p>']]);

        self::assertSame([], $this->contentFeedback());
    }

    /** @param array<int|string, array<string, mixed>> $records */
    private function save(array $records): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => $records], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }

    /** @return list<string> */
    private function contentFeedback(): array
    {
        $queue = GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier(self::QUEUE);
        $messages = array_map(static fn (FlashMessage $message): string => $message->getMessage(), $queue->toArray());

        return array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'This content element has')));
    }
}
