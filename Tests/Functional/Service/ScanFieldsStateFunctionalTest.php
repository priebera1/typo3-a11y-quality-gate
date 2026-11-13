<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\FieldConfigurationBootstrapService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Product acceptance F-04/F-05.
 * F-05: with every content field switched off a scan checked nothing but read like a clean result ("[OK] … records: 0").
 * F-04: a field that a TCA change added after the first discovery was silently not scanned until "Refresh fields".
 */
final class ScanFieldsStateFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10, 'pid' => 1, 'CType' => 'text', 'header' => 'Offer', 'bodytext' => '<p><img src="photo.png"></p>', 'sys_language_uid' => 0,
        ]);
        $this->get(SiteWriter::class)->createNewBasicSite('fixture', 1, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    private function insertField(string $field, int $enabled): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => $field, 'field_type' => 'rte', 'field_label' => $field, 'is_enabled' => $enabled, 'is_auto_detected' => 1,
        ]);
    }

    #[Test]
    public function aScanWithEveryFieldSwitchedOffSaysThatNothingWasChecked(): void
    {
        $this->insertField('bodytext', 0);

        $result = $this->get(ScanOrchestrator::class)->scanPage(siteIdentifier: 'fixture', pageUid: 1);

        self::assertSame(0, $result->recordsScanned);
        self::assertContains(ScanResult::WARNING_NO_SCAN_FIELDS, array_column($result->warnings, 'code'));
    }

    #[Test]
    public function aScanWithEnabledFieldsHasNoSuchWarning(): void
    {
        $this->insertField('bodytext', 1);

        $result = $this->get(ScanOrchestrator::class)->scanPage(siteIdentifier: 'fixture', pageUid: 1);

        self::assertNotContains(ScanResult::WARNING_NO_SCAN_FIELDS, array_column($result->warnings, 'code'));
        self::assertGreaterThan(0, $result->issuesNew);
    }

    #[Test]
    public function fieldsMissingFromTheStoredConfigurationAreCountedUntilRefreshed(): void
    {
        $bootstrap = $this->get(FieldConfigurationBootstrapService::class);
        self::assertSame(0, $bootstrap->countFieldsAwaitingRefresh(), 'no configuration yet: the first discovery adds every field');

        $this->insertField('bodytext', 1);
        $awaiting = $bootstrap->countFieldsAwaitingRefresh();
        self::assertGreaterThan(0, $awaiting, 'TCA offers more scannable fields (image, media, assets) than the stored one');

        $discovered = $this->get(\Priebera\A11yQualityGate\Service\TcaFieldDiscoveryService::class)->discover();
        $this->get(\Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository::class)->refreshFromDiscovery($discovered);
        self::assertSame(0, $bootstrap->countFieldsAwaitingRefresh(), 'after Refresh fields nothing waits');
    }
}
