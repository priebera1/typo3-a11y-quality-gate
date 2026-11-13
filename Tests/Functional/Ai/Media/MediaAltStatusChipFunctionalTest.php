<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Form\Event\ModifyFileReferenceControlsEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Atlas audit F-04 (c): TYPO3 dispatches ModifyFileReferenceControlsEvent for every image header of a content element,
 * and AQG adds the ALT status chip of the stored record (registered with #[AsEventListener]).
 */
final class MediaAltStatusChipFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', ['uid' => 50, 'pid' => 0, 'file' => 5, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'alternative' => 'Chart of water prices', 'title' => '', 'description' => '']);
        $this->setUpBackendUser(1);
    }

    /** @param array<string,mixed> $record */
    private function chip(array $record, string $parentTable = 'tt_content', int $fileType = 2): string
    {
        $event = new ModifyFileReferenceControlsEvent(
            ['edit' => '<a>edit</a>'],
            ['inlineParentTableName' => $parentTable],
            $record + ['uid' => 20, 'sys_language_uid' => 0, 'uid_local' => [['uid' => 5, 'table' => 'sys_file', 'row' => ['uid' => 5, 'type' => $fileType]]]],
        );
        $this->get(EventDispatcherInterface::class)->dispatch($event);

        return $event->hasControl('aqgAltStatus') ? $event->getControl('aqgAltStatus') : '';
    }

    #[Test]
    public function theHeaderShowsTheStoredAltState(): void
    {
        self::assertStringContainsString('data-aqg-alt-chip="inherited"', $this->chip(['alternative' => null, 'tx_a11y_is_decorative' => 0]));
        self::assertStringContainsString('data-aqg-alt-chip="missing"', $this->chip(['alternative' => '', 'tx_a11y_is_decorative' => 0]));
        self::assertStringContainsString('data-aqg-alt-chip="own"', $this->chip(['alternative' => 'Bar chart', 'tx_a11y_is_decorative' => 0]));
        self::assertStringContainsString('data-aqg-alt-chip="decorative"', $this->chip(['alternative' => '', 'tx_a11y_is_decorative' => 1]));
    }

    #[Test]
    public function theChipCarriesWhatTheFormNeedsToKeepItCurrentWithoutAi(): void
    {
        $chip = $this->chip(['alternative' => null, 'tx_a11y_is_decorative' => 0]);

        self::assertStringContainsString('data-aqg-alt-input="data[sys_file_reference][20][alternative]"', $chip);
        self::assertStringContainsString('data-aqg-alt-file-default="1"', $chip);
        self::assertStringContainsString('data-aqg-alt-decorative="0"', $chip);
        self::assertStringContainsString('data-aqg-alt-decorative="1"', $this->chip(['alternative' => '', 'tx_a11y_is_decorative' => 1]));
        preg_match('/data-aqg-alt-labels="([^"]*)"/', $chip, $match);
        $labels = json_decode(html_entity_decode($match[1] ?? ''), true);
        self::assertSame('ALT missing', $labels['altMissing'] ?? null);
        self::assertSame('Filled in, unsaved', $labels['stepFilled'] ?? null);
    }

    #[Test]
    public function otherParentsAndNonImagesGetNoChip(): void
    {
        self::assertSame('', $this->chip(['alternative' => ''], 'pages'));
        self::assertSame('', $this->chip(['alternative' => ''], 'tt_content', 4));
    }
}
