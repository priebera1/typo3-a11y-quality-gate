<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Hook;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Hook\FileReferenceDecorativeHook;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FileReferenceDecorativeDataHandlerTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'username' => 'admin',
            'admin' => 1,
            'disable' => 0,
            'deleted' => 0,
        ]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Fixture page',
            'doktype' => 1,
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Fixture content',
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 20,
            'pid' => 1,
            'uid_local' => 1,
            'uid_foreign' => 10,
            'tablenames' => 'tt_content',
            'fieldname' => 'assets',
            'alternative' => '',
            'tx_a11y_is_decorative' => 1,
            'sys_language_uid' => 0,
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function hookIsPubliclyResolvableThroughTheContainer(): void
    {
        self::assertInstanceOf(
            FileReferenceDecorativeHook::class,
            GeneralUtility::makeInstance(FileReferenceDecorativeHook::class),
        );
    }

    #[Test]
    public function nonEmptyFormEngineAlternativeWinsOverStaleDecorativeFlag(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_file_reference' => [
                20 => [
                    'alternative' => 'A reviewed manual description',
                    'tx_a11y_is_decorative' => 1,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->fetchAssociative(
                'SELECT alternative, tx_a11y_is_decorative FROM sys_file_reference WHERE uid = 20',
            );
        self::assertSame('A reviewed manual description', $row['alternative']);
        self::assertSame(0, (int)$row['tx_a11y_is_decorative']);
    }

    #[Test]
    public function decorativeFormEngineSaveClearsAlternativeAtomically(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_file_reference' => [
                20 => [
                    'alternative' => '',
                    'tx_a11y_is_decorative' => 1,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->fetchAssociative(
                'SELECT alternative, tx_a11y_is_decorative FROM sys_file_reference WHERE uid = 20',
            );
        self::assertSame('', $row['alternative']);
        self::assertSame(1, (int)$row['tx_a11y_is_decorative']);
    }
}
