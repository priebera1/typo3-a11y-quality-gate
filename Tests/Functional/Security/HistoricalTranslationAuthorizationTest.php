<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * IA-MCP-01 (independent MCP audit, 2026-11-05) inverted: the translation recorded with a stored site-scan result only
 * identifies its owner. The page's current translation decides, with the user's rights at call time, and a recorded
 * translation that is no longer a live translation of that page in that language withholds the result — in MCP and in
 * every native consumer of RemoteResultAuthorizationService (findings, totals, CSV, scan notification, toolbar).
 *
 * Result page 54 (https://a.test/de/oeffentlich) is stamped with owner page 2, language 1, translation 20 and carries
 * "german-rule" (element on tt_content 403) plus the page-level finding "translated-document-title".
 */
final class HistoricalTranslationAuthorizationTest extends AbstractFunctionalTestCase
{
    use RemoteResultFixtureTrait;

    private const GERMAN_RULES = ['german-rule', 'translated-document-title'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRemoteResultFixture();
        $this->get(McpSiteSettingsRepository::class)->save('client-a', true, ['findings:read', 'history:read', 'scans:run-page'], 1);
        $this->stamp();
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
            'uid' => 186, 'remote_scan' => 1, 'remote_scan_page' => 54, 'rule_id' => 'translated-document-title', 'impact' => 'serious',
            'help' => 'TRANSLATED_PAGE_LEVEL_MARKER', 'nodes_count' => 0, 'crdate' => time(), 'tstamp' => time(),
        ]);
    }

    #[Test]
    public function theRecordedTranslationIsReadableWhileItIsTheCurrentReadableTranslation(): void
    {
        $owner = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')
            ->select(['owner_page_uid', 'owner_language_uid', 'owner_translation_uid'], 'tx_a11y_remote_scan_page', ['uid' => 54])->fetchAssociative();
        self::assertSame([2, 1, 20], array_map('intval', array_values($owner)));
        $this->assertGermanVisible(self::EDITOR, true, 'positive control: the original translation is readable');
        self::assertStringContainsString('GERMAN_CONTENT', $this->csv(self::EDITOR));
    }

    #[Test]
    public function revokingTheRecordedTranslationWithholdsItAndRestoringItShowsItAgain(): void
    {
        $this->pages()->update('pages', ['perms_everybody' => 0], ['uid' => 20]);
        $this->assertGermanVisible(self::EDITOR, false, 'the original translation is refused');
        $this->assertGermanVisible(self::ADMIN, true, 'administrators read it');
        $this->pages()->update('pages', ['perms_everybody' => 31], ['uid' => 20]);
        $this->assertGermanVisible(self::EDITOR, true, 'restored');
    }

    #[Test]
    public function aReplacementTranslationRefusedToTheUserWithholdsTheHistoricalResultEverywhere(): void
    {
        // SOL's reproduction: the readable translation 20 is re-parented; the page's live translation is now 21, refused.
        $before = $this->native(self::EDITOR);
        $this->pages()->update('pages', ['l10n_parent' => 1, 'slug' => '/old-translation'], ['uid' => 20]);
        $this->insertTranslation(21, 2, 1, 0);
        self::assertFalse($this->as(self::EDITOR, fn (): bool => $this->get(ScopeAccessService::class)->canReadPageInLanguage(2, 1)), 'the current translation is refused');

        $mcp = $this->results(self::EDITOR);
        self::assertSame([], array_values(array_intersect(self::GERMAN_RULES, array_column($mcp['findings'], 'ruleId'))));
        $this->assertNoneOf(['GERMAN_CONTENT', 'TRANSLATED_PAGE_LEVEL_MARKER', '/de/oeffentlich', 'Page https://a.test/de/'], $mcp);
        self::assertSame(count($mcp['findings']), $mcp['total'], 'withheld findings do not count');
        self::assertSame($mcp['total'], $mcp['summary']['issueTypes']);
        self::assertNotContains('german-rule', array_column($mcp['summary']['topIssueTypes'], 'ruleId'));

        $after = $this->native(self::EDITOR);
        self::assertSame([], array_values(array_intersect(self::GERMAN_RULES, $after['rules'])));
        self::assertArrayNotHasKey(54, $after['pages']);
        self::assertSame($before['summary']['issueTypesTotal'] - 2, $after['summary']['issueTypesTotal']);
        // german-rule's one element plus the page-level finding, which counts once.
        self::assertSame($before['summary']['occurrencesTotal'] - 2, $after['summary']['occurrencesTotal']);
        self::assertSame($before['summary']['pagesExpected'] - 1, $after['summary']['pagesExpected']);
        self::assertSame($before['toolbar'] - 2, $after['toolbar']);
        self::assertStringNotContainsString('GERMAN_CONTENT', $this->csv(self::EDITOR));
        self::assertSame('', $this->as(self::EDITOR, fn (): string => $this->get(RemoteExportBuilder::class)->buildPageCsv(54)));

        $this->assertGermanVisible(self::ADMIN, true, 'administrators read it after the replacement');
        self::assertStringContainsString('GERMAN_CONTENT', $this->csv(self::ADMIN));
    }

    #[Test]
    public function aRecordedTranslationThatIsNoLongerALiveTranslationOfThePageFailsClosed(): void
    {
        // Re-parented while the page's current translation is readable: the stored relationship is inconsistent.
        $this->pages()->update('pages', ['l10n_parent' => 1], ['uid' => 20]);
        $this->insertTranslation(21, 2, 1, 31);
        $this->assertGermanVisible(self::EDITOR, false, 're-parented, readable replacement');
        $this->assertGermanVisible(self::ADMIN, true, 'administrators');
    }

    #[Test]
    public function aDeletedRecordedTranslationFailsClosedWithAndWithoutAReplacement(): void
    {
        $this->pages()->update('pages', ['deleted' => 1], ['uid' => 20]);
        $this->assertGermanVisible(self::EDITOR, false, 'deleted, no current translation');
        $this->insertTranslation(21, 2, 1, 31);
        $this->assertGermanVisible(self::EDITOR, false, 'deleted, readable replacement');
        $this->assertGermanVisible(self::ADMIN, true, 'administrators');
    }

    #[Test]
    public function aRecordedTranslationMovedToAnotherLanguageFailsClosed(): void
    {
        $this->pages()->update('pages', ['sys_language_uid' => 2], ['uid' => 20]);
        $this->assertGermanVisible(self::EDITOR, false, 'now a translation in another language');
    }

    #[Test]
    public function aRecordedTranslationNeedsItsOwnAndTheCurrentTranslationsReadAccess(): void
    {
        // A second live translation of the page in the same language with a lower uid is what TYPO3 resolves first.
        $this->insertTranslation(19, 2, 1, 0);
        $this->assertGermanVisible(self::EDITOR, false, 'the current (first) translation is refused');
        $this->pages()->update('pages', ['perms_everybody' => 31], ['uid' => 19]);
        $this->assertGermanVisible(self::EDITOR, true, 'both readable');
    }

    #[Test]
    public function aLanguageRootWithoutARecordedTranslationIsDecidedByItsCurrentTranslation(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://a.test/de/'], ['uid' => 50]);
        $this->insertTranslation(21, 1, 1, 31);
        $this->stamp();
        self::assertSame(0, (int)$pool->getConnectionForTable('tx_a11y_remote_scan_page')->select(['owner_translation_uid'], 'tx_a11y_remote_scan_page', ['uid' => 50])->fetchOne());
        self::assertContains('root-rule', $this->native(self::EDITOR)['rules'], 'readable current translation');
        $this->pages()->update('pages', ['perms_everybody' => 0], ['uid' => 21]);
        self::assertNotContains('root-rule', $this->native(self::EDITOR)['rules'], 'refused current translation');
        $this->pages()->update('pages', ['deleted' => 1], ['uid' => 21]);
        self::assertContains('root-rule', $this->native(self::EDITOR)['rules'], 'no translation: the default page was rendered');
    }

    #[Test]
    public function defaultLanguageResultsAreUnaffected(): void
    {
        $this->pages()->update('pages', ['deleted' => 1], ['uid' => 20]);
        $rules = $this->native(self::EDITOR)['rules'];
        foreach (['root-rule', 'mixed-rule', 'document-title'] as $rule) {
            self::assertContains($rule, $rules);
        }
    }

    private function assertGermanVisible(int $beUserUid, bool $visible, string $message): void
    {
        $mcp = array_column($this->results($beUserUid)['findings'], 'ruleId');
        $native = $this->native($beUserUid)['rules'];
        foreach (self::GERMAN_RULES as $rule) {
            self::assertSame($visible, in_array($rule, $mcp, true), 'MCP ' . $rule . ': ' . $message);
            self::assertSame($visible, in_array($rule, $native, true), 'native ' . $rule . ': ' . $message);
        }
    }

    /** @return array{rules: list<string>, pages: array<int, bool>, summary: array<string, mixed>, toolbar: int} */
    private function native(int $beUserUid): array
    {
        $scan = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 1])->fetchAssociative();

        return $this->as($beUserUid, function () use ($scan): array {
            $authorization = $this->get(RemoteResultAuthorizationService::class)->authorize($scan);

            return [
                'rules' => array_column($authorization->findings, 'ruleId'),
                'pages' => $authorization->resultPages,
                'summary' => $this->get(ScanResultSummaryService::class)->frontendResult($scan),
                'toolbar' => (int)(new \ReflectionMethod(A11yScanToolbarItem::class, 'withVisibleOccurrences'))->invoke($this->get(A11yScanToolbarItem::class), $scan)['issues_total'],
            ];
        });
    }

    private function csv(int $beUserUid): string
    {
        return $this->as($beUserUid, fn (): string => $this->get(RemoteExportBuilder::class)->buildOverviewCsv('client-a', 1));
    }

    /** @return array<string, mixed> */
    private function results(int $beUserUid): array
    {
        return $this->get(McpToolExecutor::class)->execute('aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_SITE, 'pageSize' => 50], [
            'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaab',
            'tokenSuffix' => 'test',
            'clientName' => 'Test agent',
            'beUserUid' => $beUserUid,
            'scopes' => ['findings:read', 'history:read', 'scans:run-page'],
            'siteIdentifiers' => ['client-a'],
            'expiresAt' => gmdate(DATE_ATOM, time() + 3600),
        ]);
    }

    private function insertTranslation(int $uid, int $parent, int $language, int $perms): void
    {
        $this->pages()->insert('pages', [
            'uid' => $uid, 'pid' => 1, 'title' => 'Translation ' . $uid, 'doktype' => 1, 'slug' => '/translation-' . $uid,
            'sys_language_uid' => $language, 'l10n_parent' => $parent, 'perms_userid' => 0, 'perms_groupid' => 0, 'perms_user' => 0,
            'perms_group' => 0, 'perms_everybody' => $perms, 'deleted' => 0, 'hidden' => 0,
        ]);
    }

    private function pages(): \TYPO3\CMS\Core\Database\Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('pages');
    }

    private function as(int $beUserUid, \Closure $callback): mixed
    {
        $previous = $GLOBALS['BE_USER'] ?? null;
        $this->setUpBackendUser($beUserUid);
        try {
            return $callback();
        } finally {
            $GLOBALS['BE_USER'] = $previous;
        }
    }
}
