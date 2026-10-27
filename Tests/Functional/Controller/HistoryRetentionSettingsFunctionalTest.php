<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\HistoryPruneCommand;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\LocalHistoryRetentionService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Settings › Scan history with the real TYPO3 user, routing and database stack: only administrators change the period
 * or delete; saving never deletes; a deletion needs the explicit confirmation, the route token and the reviewed
 * selection, deletes exactly that selection with its pages, findings and occurrences, and a replayed, stale or changed
 * review deletes nothing. The command is a dry run without --apply and fails when the selection changes under it.
 */
final class HistoryRetentionSettingsFunctionalTest extends AbstractFunctionalTestCase
{
    private const SAVE_ROUTE = 'web_a11y.settingsHistoryRetentionSave';
    private const PRUNE_ROUTE = 'web_a11y.settingsHistoryRetentionPrune';

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        foreach ([[1, 'admin', 1], [3, 'editor', 0]] as [$uid, $username, $admin]) {
            $connection->insert('be_users', ['uid' => $uid, 'username' => $username, 'admin' => $admin, 'disable' => 0, 'deleted' => 0]);
        }
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $this->insertHistoryScan(1, 3000);
        $this->insertHistoryScan(2, 2000);
        $this->insertHistoryScan(3, 900, 'failed');
        $this->insertHistoryScan(4, 30);   // newest completed of the group: always kept
    }

    protected function tearDown(): void
    {
        // The backend request built for the controller must not leak into later tests (FAL checks it).
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function bothRoutesArePostOnlyAndNeedTheirOwnRouteToken(): void
    {
        $this->setUpBackendUser(1);
        $router = $this->get(Router::class);
        $formProtection = $this->get(FormProtectionFactory::class)->createForType('backend');
        foreach ([self::SAVE_ROUTE, self::PRUNE_ROUTE] as $name) {
            $registered = $router->getRoute($name);
            self::assertIsObject($registered, $name);
            self::assertSame(['POST'], $registered->getMethods(), $name);
            self::assertNotSame('public', $registered->getOption('access'), $name);

            $route = $router->match($registered->getPath());
            $request = $this->request(['localHistoryRetention' => '1y'])->withAttribute('route', $route);
            try {
                $this->get(RouteDispatcher::class)->dispatch($request);
                self::fail($name . ': a request without token must be refused.');
            } catch (MissingRequestTokenException) {
            }
            try {
                $this->get(RouteDispatcher::class)->dispatch($request->withParsedBody([
                    'localHistoryRetention' => '1y',
                    'token' => $formProtection->generateToken('route', $name === self::SAVE_ROUTE ? self::PRUNE_ROUTE : self::SAVE_ROUTE),
                ]));
                self::fail($name . ': the token of the other route must be refused.');
            } catch (InvalidRequestTokenException) {
            }
        }
        self::assertSame('keep', $this->service()->getSetting());
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));
    }

    #[Test]
    public function anEditorCanNeitherSaveNorDelete(): void
    {
        $this->service()->saveSetting('1y');
        $preview = $this->service()->preview();
        self::assertSame(3, $preview['scans']);

        $this->setUpBackendUser(3);
        $controller = $this->get(SettingsController::class);
        $save = $controller->saveHistoryRetentionAction($this->request(['localHistoryRetention' => 'keep'], true));
        $prune = $controller->pruneHistoryRetentionAction($this->request([
            'confirmDeletion' => '1',
            'reviewedCutoff' => (string)$preview['cutoff'],
            'reviewToken' => $preview['reviewToken'],
        ], true));

        self::assertSame(403, $save->getStatusCode());
        self::assertSame(403, $prune->getStatusCode());
        self::assertStringContainsString('admin_only_settings_required', (string)$prune->getBody());
        self::assertSame('1y', $this->service()->getSetting());
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));
    }

    #[Test]
    public function savingAPeriodDeletesNothingAndRejectsUnknownPeriods(): void
    {
        $this->setUpBackendUser(1);
        $controller = $this->get(SettingsController::class);

        self::assertSame(303, $controller->saveHistoryRetentionAction($this->request(['localHistoryRetention' => '1y']))->getStatusCode());
        self::assertSame('1y', $this->service()->getSetting());
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN), 'saving only names the period');

        $controller->saveHistoryRetentionAction($this->request(['localHistoryRetention' => '10d']));
        self::assertSame('1y', $this->service()->getSetting(), 'an unknown period is refused, the saved one stays');
    }

    #[Test]
    public function aConfirmedReviewDeletesExactlyTheReviewedScansAndAReplayDeletesNothing(): void
    {
        $this->setUpBackendUser(1);
        $this->service()->saveSetting('5y');
        $preview = $this->service()->preview();
        self::assertSame([1, 2], $preview['uids']);
        $body = ['reviewedCutoff' => (string)$preview['cutoff'], 'reviewToken' => $preview['reviewToken']];
        $controller = $this->get(SettingsController::class);

        $controller->pruneHistoryRetentionAction($this->request($body));
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN), 'without the confirmation nothing is deleted');

        // Another administrator shortens the period after this review: the reviewed selection is still all that goes.
        $this->service()->saveSetting('1y');
        self::assertSame(303, $controller->pruneHistoryRetentionAction($this->request($body + ['confirmDeletion' => '1']))->getStatusCode());
        self::assertSame([3, 4], $this->scanUids());
        self::assertSame(0, $this->orphans());

        $controller->pruneHistoryRetentionAction($this->request($body + ['confirmDeletion' => '1']));
        self::assertSame([3, 4], $this->scanUids(), 'a replayed confirmation deletes nothing');
    }

    #[Test]
    public function aReviewForALongerPeriodThanTheSavedOneDeletesNothing(): void
    {
        $this->setUpBackendUser(1);
        $this->service()->saveSetting('1y');
        $preview = $this->service()->preview();
        $this->service()->saveSetting('5y');

        $this->get(SettingsController::class)->pruneHistoryRetentionAction($this->request([
            'confirmDeletion' => '1',
            'reviewedCutoff' => (string)$preview['cutoff'],
            'reviewToken' => $preview['reviewToken'],
        ]));

        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));
    }

    #[Test]
    public function aScanThatBecomesProtectedAfterTheReviewIsKept(): void
    {
        $this->setUpBackendUser(1);
        $this->service()->saveSetting('1y');
        $preview = $this->service()->preview();
        $this->getConnectionPool()->getConnectionForTable(Tables::FIX_VERIFICATION)
            ->insert(Tables::FIX_VERIFICATION, ['site_identifier' => 'main', 'baseline_scan' => 2, 'outcome' => 'pending']);

        $this->get(SettingsController::class)->pruneHistoryRetentionAction($this->request([
            'confirmDeletion' => '1',
            'reviewedCutoff' => (string)$preview['cutoff'],
            'reviewToken' => $preview['reviewToken'],
        ]));

        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));
    }

    /**
     * A decided Verify fix and an older evaluated monitoring run keep their outcome in their own row and no longer hold
     * scans; a pending verification, a run that is not evaluated yet (with its baseline) and the latest evaluated and
     * latest complete runs of a site and language do.
     */
    #[Test]
    public function onlyPendingVerificationsAndCurrentMonitoringBaselinesProtectScans(): void
    {
        foreach ([10 => 3100, 11 => 3050, 12 => 3000, 13 => 2950, 14 => 2900, 15 => 2850, 16 => 2800] as $uid => $daysAgo) {
            $this->insertHistoryScan($uid, $daysAgo);
        }
        $verifications = $this->getConnectionPool()->getConnectionForTable(Tables::FIX_VERIFICATION);
        $verifications->insert(Tables::FIX_VERIFICATION, ['site_identifier' => 'main', 'baseline_scan' => 10, 'verification_scan' => 11, 'outcome' => 'resolved']);
        $verifications->insert(Tables::FIX_VERIFICATION, ['site_identifier' => 'main', 'baseline_scan' => 12, 'verification_job_id' => 'job-13', 'outcome' => 'pending']);
        $runs = $this->getConnectionPool()->getConnectionForTable(Tables::MONITORING_RUN);
        $runs->insert(Tables::MONITORING_RUN, ['site_identifier' => 'main', 'language_uid' => 0, 'job_id' => 'job-14', 'status' => 'evaluated', 'coverage_complete' => 1]);
        $runs->insert(Tables::MONITORING_RUN, ['site_identifier' => 'main', 'language_uid' => 0, 'job_id' => 'job-15', 'status' => 'evaluated', 'coverage_complete' => 1]);
        // A run of another language that is not evaluated yet keeps its own scan and its baseline.
        $runs->insert(Tables::MONITORING_RUN, ['site_identifier' => 'main', 'language_uid' => 1, 'job_id' => 'job-16', 'baseline_job_id' => 'job-2', 'status' => 'submitted']);

        $this->service()->saveSetting('1y');
        $preview = $this->service()->preview();

        self::assertSame([1, 3, 10, 11, 14], $preview['uids']);
        self::assertSame(['running' => 0, 'baseline' => 0, 'verification' => 2, 'monitoring' => 3], $preview['protected']);
        self::assertSame([['site' => 'main', 'language' => 0, 'scans' => 5]], $preview['bySite']);
    }

    /**
     * While a monitoring run is not evaluated, its baseline is not known yet: every completed scan of its site and
     * language stays until the run is evaluated.
     */
    #[Test]
    public function aMonitoringRunThatIsNotEvaluatedYetKeepsTheScansItMayCompareWith(): void
    {
        $this->getConnectionPool()->getConnectionForTable(Tables::MONITORING_RUN)
            ->insert(Tables::MONITORING_RUN, ['site_identifier' => 'main', 'language_uid' => 0, 'job_id' => 'job-new', 'status' => 'submitted']);
        $this->service()->saveSetting('1y');

        $preview = $this->service()->preview();

        self::assertSame([3], $preview['uids'], 'only the failed scan can go');
        self::assertSame(2, $preview['protected']['monitoring']);
    }

    /** A newer scan whose results were never stored does not take the comparison baseline's place. */
    #[Test]
    public function theNewestStoredScanStaysTheBaselineWhenANewerOneWasNotStored(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN);
        $connection->update(Tables::REMOTE_SCAN, ['persisted_at' => time()], ['uid' => 2]);
        $connection->update(Tables::REMOTE_SCAN, ['persisted_at' => 0], ['uid' => 4]);
        $connection->update(Tables::REMOTE_SCAN, ['persisted_at' => time()], ['uid' => 1]);
        $this->service()->saveSetting('1y');

        self::assertSame([1, 3], $this->service()->preview()['uids'], 'scan 2 is the newest stored one and is kept');
    }

    #[Test]
    public function theCommandIsADryRunWithoutApplyAndDeletesTheSelectionWithApply(): void
    {
        $this->service()->saveSetting('1y');
        $tester = new CommandTester(new HistoryPruneCommand($this->service()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString(' UTC', $tester->getDisplay());
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('3 scans deleted', $tester->getDisplay());
        self::assertSame([4], $this->scanUids());
        self::assertSame(0, $this->orphans());

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('Nothing to delete', $tester->getDisplay());
        self::assertSame([4], $this->scanUids(), 'idempotent');
    }

    #[Test]
    public function theCommandKeepsHistoryWithTheDefaultPeriod(): void
    {
        $tester = new CommandTester(new HistoryPruneCommand($this->service()));

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
        self::assertSame(4, $this->rowCount(Tables::REMOTE_SCAN));
    }

    private function service(): LocalHistoryRetentionService
    {
        return new LocalHistoryRetentionService($this->get(ConnectionPool::class), GeneralUtility::makeInstance(Registry::class));
    }

    /** @param array<string, string> $body */
    private function request(array $body, bool $json = false): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/settings/history-retention', 'POST'))
            ->withParsedBody($body + ['tab' => 'history'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $json ? $request->withHeader('Accept', 'application/json') : $request;
    }

    private function insertHistoryScan(int $uid, int $daysAgo, string $status = 'completed'): void
    {
        $pool = $this->getConnectionPool();
        $finished = time() - $daysAgo * 86400;
        $pool->getConnectionForTable(Tables::REMOTE_SCAN)->insert(Tables::REMOTE_SCAN, [
            'uid' => $uid, 'site_identifier' => 'main', 'job_id' => 'job-' . $uid, 'source_type' => 'sitemap',
            'scan_scope' => 'site', 'language_uid' => 0, 'start_url' => 'https://example.org/', 'status' => $status,
            'finished_at' => $finished, 'crdate' => $finished,
        ]);
        $pool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->insert(Tables::REMOTE_SCAN_PAGE, ['uid' => $uid * 10, 'remote_scan' => $uid, 'url' => 'https://example.org/']);
        $pool->getConnectionForTable(Tables::REMOTE_ISSUE)->insert(Tables::REMOTE_ISSUE, ['uid' => $uid * 10, 'remote_scan' => $uid, 'remote_scan_page' => $uid * 10, 'rule_id' => 'image-alt', 'nodes_count' => 2]);
        $pool->getConnectionForTable(Tables::REMOTE_ISSUE_NODE)->insert(Tables::REMOTE_ISSUE_NODE, ['remote_issue' => $uid * 10, 'html_snippet' => '<img>']);
    }

    private function rowCount(string $table): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable($table)->count('*', $table, []);
    }

    /** @return list<int> */
    private function scanUids(): array
    {
        $uids = array_map('intval', $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)
            ->select(['uid'], Tables::REMOTE_SCAN)->fetchFirstColumn());
        sort($uids);

        return $uids;
    }

    /** Pages, findings or occurrences left without their scan, finding or page. */
    private function orphans(): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN);

        return (int)$connection->executeQuery(
            'SELECT (SELECT COUNT(*) FROM tx_a11y_remote_scan_page p LEFT JOIN tx_a11y_remote_scan s ON s.uid = p.remote_scan WHERE s.uid IS NULL)'
            . ' + (SELECT COUNT(*) FROM tx_a11y_remote_issue i LEFT JOIN tx_a11y_remote_scan s ON s.uid = i.remote_scan WHERE s.uid IS NULL)'
            . ' + (SELECT COUNT(*) FROM tx_a11y_remote_issue i LEFT JOIN tx_a11y_remote_scan_page p ON p.uid = i.remote_scan_page WHERE p.uid IS NULL)'
            . ' + (SELECT COUNT(*) FROM tx_a11y_remote_issue_node n LEFT JOIN tx_a11y_remote_issue i ON i.uid = n.remote_issue WHERE i.uid IS NULL)'
        )->fetchOne();
    }
}
