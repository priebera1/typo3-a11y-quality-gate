<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Hook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Hook\PublishHook;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\QualityGate\QualityGateVerdict;
use Priebera\A11yQualityGate\Scan\ContentCollector;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * A publish decision must say which scan it rests on, and a publish without a decision must not pass
 * silently while the Quality Gate is switched on. The decision logic itself is QualityGateChecker's.
 */
final class PublishHookTest extends TestCase
{
    /** @var list<array{message: string, severity: ContextualFeedbackSeverity}> */
    private array $flashMessages = [];

    #[Test]
    public function warningNamesTheContentScanTheDecisionIsBasedOn(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 2, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['2 critical issues exceed threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 2, 'threshold' => 0]],
        ));

        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));

        $this->unhidePage($checker, $scans);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $this->flashMessages[0]['severity']);
        self::assertStringContainsString('2 critical issues exceed threshold 0', $this->flashMessages[0]['message']);
        self::assertMatchesRegularExpression(
            '/Decision based on the content scan from \d{2}\.\d{2}\.\d{4} \d{2}:\d{2}\.$/',
            $this->flashMessages[0]['message']
        );
    }

    #[Test]
    public function aWarningSaysThePageIsAlreadyVisibleAndNamesTheIssuesToFix(): void
    {
        // M-03 (2026-11-05 company acceptance): Settings promised "Editors confirm to continue"; the page was already
        // public when the warning appeared, and the warning named no issue.
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 2, 'warning' => 1, 'info' => 0, 'needs_review' => 0],
            reasons: ['2 critical issues exceed threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 2, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willReturn(new ScanResult(11));

        $this->unhidePage($checker, $scans, openIssues: [
            ['rule_id' => 'rte.empty_link', 'severity' => 1, 'message' => 'Link has no accessible name.'],
            ['rule_id' => 'rte.empty_link', 'severity' => 1, 'message' => 'Link has no accessible name.'],
            ['rule_id' => 'rte.non_descriptive_link', 'severity' => 2, 'message' => 'Link text "Click here" is not descriptive.'],
            ['rule_id' => 'rte.pdf_hint', 'severity' => 3, 'message' => 'Info only.'],
        ]);

        $message = $this->flashMessages[0]['message'];
        self::assertStringStartsWith('The page is now visible. It does not meet the Quality Gate:', $message);
        self::assertStringContainsString('To fix: Link has no accessible name (2×); Link text "Click here" is not descriptive.', $message);
        self::assertStringNotContainsString('Info only', $message);
        self::assertMatchesRegularExpression('/Decision based on the content scan from .+\.$/', $message);
    }

    #[Test]
    public function countsAndReasonsUseSingularAndPluralInsteadOfParentheses(): void
    {
        // Atlas audit F-26: "2 warning, 1 info accessibility issue(s)" / "1 critical issue(s) exceed threshold 0".
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 0, 'warning' => 2, 'info' => 1, 'needs_review' => 0],
            reasons: ['2 warnings exceed threshold 1'],
            reasonDetails: [['severity' => 'warning', 'count' => 2, 'threshold' => 1]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willReturn(new ScanResult(11));

        $this->unhidePage($checker, $scans);

        $message = $this->flashMessages[0]['message'];
        self::assertStringContainsString('2 warnings exceed threshold 1', $message);
        self::assertStringContainsString('2 warnings, 1 info', $message);
        self::assertStringNotContainsString('(s)', $message);

        $this->flashMessages = [];
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 0, 'warning' => 1, 'info' => 0, 'needs_review' => 0],
            reasons: ['1 warning exceeds threshold 0'],
            reasonDetails: [['severity' => 'warning', 'count' => 1, 'threshold' => 0]],
        ));
        $this->unhidePage($checker, $scans);

        $message = $this->flashMessages[0]['message'];
        self::assertStringContainsString('1 warning exceeds threshold 0', $message);
        self::assertStringContainsString('Current open findings: 1 warning.', $message);
        self::assertStringNotContainsString('(s)', $message);
    }

    #[Test]
    public function failedScanIsReportedInsteadOfPublishingWithoutADecision(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->with('main')->willReturn(true);
        $checker->expects(self::never())->method('check');

        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willThrowException(new \RuntimeException('Rendered page fetch failed at https://internal.example/'));

        $this->unhidePage($checker, $scans);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $this->flashMessages[0]['severity']);
        self::assertStringContainsString('could not check this page', $this->flashMessages[0]['message']);
        self::assertStringNotContainsString('internal.example', $this->flashMessages[0]['message']);
    }

    #[Test]
    public function failedScanStaysQuietWhileTheQualityGateIsDisabled(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(false);
        $checker->expects(self::never())->method('check');

        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willThrowException(new \RuntimeException('scan failed'));

        $this->unhidePage($checker, $scans);

        self::assertSame([], $this->flashMessages);
    }

    #[Test]
    public function editorWithoutAqgModuleAccessIsStillWarnedByTheGate(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['1 critical issue exceeds threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 1, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));

        $this->unhidePage($checker, $scans, false);

        self::assertCount(1, $this->flashMessages);
        self::assertStringContainsString('1 critical issue exceeds threshold 0', $this->flashMessages[0]['message']);
    }

    #[Test]
    public function blockingGateRehidesThePageForAnEditorWithoutAqgModuleAccess(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 2,
            counts: ['critical' => 3, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['3 critical issues exceed threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 3, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willReturn(new ScanResult(11));

        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $connection->expects(self::once())->method('update')->with('pages', ['hidden' => 1], ['uid' => 42]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, false, $pool, true);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages[0]['severity']);
    }

    #[Test]
    public function contentFeedbackStaysWithAqgModuleUsers(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $scans = $this->createMock(ScanOrchestrator::class);
        // Only the page unhide in the datamap is scanned; the content element change is not.
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));
        $checker->method('check')->willReturn(QualityGateVerdict::pass());

        $this->unhidePage($checker, $scans, false, null, false, ['tt_content' => [7 => ['pid' => 42, 'bodytext' => '<p>x</p>']]]);

        self::assertSame([], $this->flashMessages);
    }

    #[Test]
    public function unhidingATranslationJudgesItsDefaultPageInItsLanguageAndRehidesTheTranslation(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->expects(self::once())->method('check')->with(42, 'main', 1)->willReturn(QualityGateVerdict::fail(
            mode: 2,
            counts: ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['1 critical issue exceeds threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 1, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')
            ->with(self::anything(), 42, 1, self::anything(), self::anything())
            ->willReturn(new ScanResult(11));

        // The page tree sends only `hidden` for the German record 77 of page 42.
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $connection->method('fetchAssociative')->willReturn(['l10n_parent' => 42, 'sys_language_uid' => 1]);
        $connection->expects(self::once())->method('update')->with('pages', ['hidden' => 1], ['uid' => 77]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true, [], 77);

        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages[0]['severity'] ?? null);
    }

    #[Test]
    public function aDefaultLanguagePageIsJudgedAsItself(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->expects(self::once())->method('check')->with(42, 'main', 0)->willReturn(QualityGateVerdict::pass());
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')
            ->with(self::anything(), 42, 0, self::anything(), self::anything())
            ->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $connection->method('fetchAssociative')->willReturn(['l10n_parent' => 0, 'sys_language_uid' => 0]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool);

        self::assertSame([], $this->flashMessages);
    }

    #[Test]
    public function unhidingADefaultPageAlsoJudgesEveryVisibleTranslationInItsOwnLanguage(): void
    {
        // ML-01: a hidden default-language page takes all its translations offline. Unhiding it publishes every
        // translation whose own record is visible, so each one must pass the gate on its own findings: a clean
        // English page must not let a German translation with a critical issue go live unchecked.
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(true);
        $checker->expects(self::exactly(3))->method('check')->willReturnCallback(
            static fn (int $pageUid, string $site, int $languageUid): QualityGateVerdict => $languageUid === 1
                ? QualityGateVerdict::fail(
                    mode: 2,
                    counts: ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
                    reasons: ['1 critical issue exceeds threshold 0'],
                    reasonDetails: [['severity' => 'critical', 'count' => 1, 'threshold' => 0]],
                )
                : QualityGateVerdict::pass(mode: 2)
        );
        $scannedLanguages = [];
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::exactly(3))->method('scanPage')->willReturnCallback(
            static function (string $site, int $pageUid, int $languageUid = -1) use (&$scannedLanguages): ScanResult {
                $scannedLanguages[] = [$pageUid, $languageUid];
                return new ScanResult(11);
            }
        );
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $connection->method('fetchAssociative')->willReturn(['l10n_parent' => 0, 'sys_language_uid' => 0]);
        // Visible live translations of page 42: German 77 (language 1) and Slovak 78 (language 3).
        $connection->method('fetchAllAssociative')->willReturn([
            ['uid' => 77, 'sys_language_uid' => 1],
            ['uid' => 78, 'sys_language_uid' => 3],
        ]);
        // Only the failing translation goes offline again; the default page and the Slovak translation stay visible.
        $connection->expects(self::once())->method('update')->with('pages', ['hidden' => 1], ['uid' => 77]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true);

        self::assertSame([[42, 0], [42, 1], [42, 3]], $scannedLanguages);
        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages[0]['severity']);
        self::assertStringContainsString('German', $this->flashMessages[0]['message']);
    }

    #[Test]
    public function aBlockedDefaultPageLeavesItsTranslationsUntouched(): void
    {
        // Re-hiding the default page takes every translation offline again; they are neither judged nor re-hidden.
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(true);
        $checker->expects(self::once())->method('check')->with(42, 'main', 0)->willReturn(QualityGateVerdict::fail(
            mode: 2,
            counts: ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['1 critical issue exceeds threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 1, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $connection->method('fetchAssociative')->willReturn(['l10n_parent' => 0, 'sys_language_uid' => 0]);
        $connection->method('fetchAllAssociative')->willReturn([['uid' => 77, 'sys_language_uid' => 1]]);
        $connection->expects(self::once())->method('update')->with('pages', ['hidden' => 1], ['uid' => 42]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true);

        self::assertCount(1, $this->flashMessages);
        self::assertStringNotContainsString('Translation', $this->flashMessages[0]['message']);
    }

    #[Test]
    public function aTranslationWarningNamesItsLanguage(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(true);
        $checker->method('check')->willReturnCallback(
            static fn (int $pageUid, string $site, int $languageUid): QualityGateVerdict => $languageUid === 3
                ? QualityGateVerdict::fail(
                    mode: 1,
                    counts: ['critical' => 2, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
                    reasons: ['2 critical issues exceed threshold 0'],
                    reasonDetails: [['severity' => 'critical', 'count' => 2, 'threshold' => 0]],
                )
                : QualityGateVerdict::pass(mode: 1)
        );
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $connection->method('fetchAssociative')->willReturn(['l10n_parent' => 0, 'sys_language_uid' => 0]);
        $connection->method('fetchAllAssociative')->willReturn([['uid' => 78, 'sys_language_uid' => 3]]);
        $connection->expects(self::never())->method('update');
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $this->flashMessages[0]['severity']);
        self::assertStringContainsString('Slovak', $this->flashMessages[0]['message']);
    }

    #[Test]
    public function aTranslationUnhiddenInTheSameSaveIsJudgedOnce(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(true);
        $judged = [];
        $checker->expects(self::exactly(2))->method('check')->willReturnCallback(
            static function (int $pageUid, string $site, int $languageUid) use (&$judged): QualityGateVerdict {
                $judged[] = [$pageUid, $languageUid];
                return QualityGateVerdict::pass(mode: 2);
            }
        );
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::exactly(2))->method('scanPage')->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        // Both records were hidden before the save and are visible after it.
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params): int {
            static $seen = [];
            $uid = (int)($params[0] ?? 0);
            $first = !isset($seen[$uid]);
            $seen[$uid] = true;
            return $first ? 1 : 0;
        });
        $connection->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array => (int)$params[0] === 77
                ? ['l10n_parent' => 42, 'sys_language_uid' => 1]
                : ['l10n_parent' => 0, 'sys_language_uid' => 0]
        );
        $connection->method('fetchAllAssociative')->willReturn([['uid' => 77, 'sys_language_uid' => 1]]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        // The translation comes first in the datamap; the default page is still judged first.
        $this->unhidePage($checker, $scans, true, $pool, true, ['pages' => [77 => ['hidden' => 0]]], pageFirst: false);

        self::assertSame([[42, 0], [42, 1]], $judged);
    }

    #[Test]
    public function aTranslationUnhiddenWithItsBlockedDefaultPageStaysOfflineAndUnjudged(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(true);
        $checker->expects(self::once())->method('check')->with(42, 'main', 0)->willReturn(QualityGateVerdict::fail(
            mode: 2,
            counts: ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['1 critical issue exceeds threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 1, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params): int {
            static $seen = [];
            $uid = (int)($params[0] ?? 0);
            $first = !isset($seen[$uid]);
            $seen[$uid] = true;
            return $first ? 1 : 0;
        });
        $connection->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array => (int)$params[0] === 77
                ? ['l10n_parent' => 42, 'sys_language_uid' => 1]
                : ['l10n_parent' => 0, 'sys_language_uid' => 0]
        );
        $connection->expects(self::once())->method('update')->with('pages', ['hidden' => 1], ['uid' => 42]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true, ['pages' => [77 => ['hidden' => 0]]], pageFirst: false);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages[0]['severity']);
    }

    #[Test]
    public function withTheGateSwitchedOffTranslationsAreNotScannedOnUnhide(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(false);
        $checker->expects(self::once())->method('check')->willReturn(QualityGateVerdict::pass());
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $connection->method('fetchAssociative')->willReturn(['l10n_parent' => 0, 'sys_language_uid' => 0]);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true);

        self::assertSame([], $this->flashMessages);
    }

    #[Test]
    public function savingAPageThatIsAlreadyVisibleDoesNotTakeItOffline(): void
    {
        // FormEngine sends hidden = 0 with every save of a visible page, e.g. after a title change.
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->expects(self::never())->method('check');
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::never())->method('scanPage');
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::never())->method('update');
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true);

        self::assertSame([], $this->flashMessages);
    }

    #[Test]
    public function unhidingAHiddenPageIsJudgedAndBlocked(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->expects(self::once())->method('check')->with(42, 'main', 0)->willReturn(QualityGateVerdict::fail(
            mode: 2,
            counts: ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['1 critical issue exceeds threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 1, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        // Hidden before the save, visible after it (G-01: only a page that really became visible is gated).
        $reads = 0;
        $connection->method('fetchOne')->willReturnCallback(static function () use (&$reads): int {
            return $reads++ === 0 ? 1 : 0;
        });
        $connection->method('fetchAssociative')->willReturn(['l10n_parent' => 0, 'sys_language_uid' => 0]);
        $connection->expects(self::once())->method('update')->with('pages', ['hidden' => 1], ['uid' => 42]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool, true);

        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages[0]['severity'] ?? null);
    }

    #[Test]
    public function aPageStateThatCannotBeReadStillRunsTheGate(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->expects(self::once())->method('check')->willReturn(QualityGateVerdict::pass());
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('connection lost'));
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, true, $pool);

        self::assertSame([], $this->flashMessages);
    }

    #[Test]
    public function inAWorkspaceNothingIsScannedJudgedOrRehiddenAndTheEditorIsTold(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->expects(self::never())->method('check');
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::never())->method('scanPage');
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::never())->method('update');
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        // The save created workspace versions (a draft page made visible, a changed text element); the content
        // scan reads live records only, so a decision would describe the live page, not this draft.
        $this->unhidePage($checker, $scans, true, $pool, true, ['tt_content' => [10 => ['bodytext' => '<p>x</p>']]], workspace: 3);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::INFO, $this->flashMessages[0]['severity']);
        self::assertStringContainsString('when the workspace is published', $this->flashMessages[0]['message']);
    }

    private function hiddenThenVisible(\PHPUnit\Framework\MockObject\MockObject $connection): void
    {
        $reads = 0;
        $connection->method('fetchOne')->willReturnCallback(static function () use (&$reads): int {
            return $reads++ === 0 ? 1 : 0;
        });
    }

    /** A page that is hidden before the save and visible after it, as DataHandler leaves an accepted unhide. */
    private function unhiddenPagePool(): ConnectionPool
    {
        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $this->hiddenThenVisible($connection);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        return $pool;
    }

    /**
     * @param array<string, mixed> $extraDatamap
     */
    private function unhidePage(
        QualityGateChecker $checker,
        ScanOrchestrator $scans,
        bool $moduleAccess = true,
        ?ConnectionPool $pool = null,
        bool $licenceValid = false,
        array $extraDatamap = [],
        int $pageUid = 42,
        int $workspace = 0,
        array $openIssues = [],
        bool $pageFirst = true,
    ): void {
        $users = $this->createMock(BackendUserService::class);
        $users->method('canAccessAccessibilityModule')->willReturn($moduleAccess);
        $users->method('getBackendUserSnapshot')->willReturn(['uid' => 1]);

        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByPageId')->willReturn(new Site('main', 1, [
            'base' => 'https://example.org/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
                ['languageId' => 3, 'title' => 'Slovak', 'locale' => 'sk_SK.UTF-8', 'base' => '/sk/'],
            ],
        ]));

        $context = $this->createMock(BackendContextService::class);
        // No backend language in unit tests: the hook falls back to its English texts.
        $context->method('translate')->willReturn('');
        $context->method('addFlashMessage')->willReturnCallback(
            function (string $message, ContextualFeedbackSeverity $severity): void {
                $this->flashMessages[] = ['message' => $message, 'severity' => $severity];
            }
        );

        $capabilities = $this->createMock(ProCapabilityService::class);
        $capabilities->method('getStatus')->willReturn(new ProStatusViewModel(
            configured: true,
            valid: $licenceValid,
            proAvailable: $licenceValid,
            plan: $licenceValid ? 'pro' : '',
            features: [],
            reason: null,
            reasonLabel: null,
            statusLabel: '',
            hasCrawler: $licenceValid,
            hasExportPdf: $licenceValid,
            hasMultiSite: false,
            hasProRules: $licenceValid,
        ));

        $issues = $this->createMock(IssueRepository::class);
        $issues->method('findOpenForPage')->willReturn($openIssues);
        $hook = new PublishHook(
            $checker,
            $scans,
            $issues,
            $sites,
            $this->createMock(ContentCollector::class),
            $users,
            $pool ?? $this->unhiddenPagePool(),
            $context,
            $capabilities,
            $this->createMock(ExtensionContextService::class),
        );

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->datamap = $pageFirst
            ? array_replace_recursive(['pages' => [$pageUid => ['hidden' => 0]]], $extraDatamap)
            : array_replace_recursive($extraDatamap, ['pages' => [$pageUid => ['hidden' => 0]]]);
        if ($workspace !== 0) {
            $user = $this->createMock(\TYPO3\CMS\Core\Authentication\BackendUserAuthentication::class);
            $user->workspace = $workspace;
            $dataHandler->BE_USER = $user;
        }

        // DataHandler calls the hook for every record before it writes, then once after all operations.
        foreach ($dataHandler->datamap as $table => $records) {
            foreach ($records as $uid => $fields) {
                $hook->processDatamap_preProcessFieldArray($fields, $table, $uid, $dataHandler);
            }
        }
        $hook->processDatamap_afterAllOperations($dataHandler);
    }
}
