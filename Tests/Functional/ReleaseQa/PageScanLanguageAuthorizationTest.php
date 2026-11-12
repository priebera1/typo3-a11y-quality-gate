<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ScanAjaxController;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The page scan route (POST a11y_scan_page, used by the toolbar's "Check saved page now", the Page module panel and
 * the Accessibility module) decides the requested language itself, with AQG's language policy (S-01): a concrete
 * language, the default language 0 included, needs the user's language access; all languages (-1) only for users
 * whose languages are not restricted. A refused request changes no scan state, stores no scan, finding or source
 * state, does not learn whether a scan is running, and answers exactly like a page the user may not edit.
 */
final class PageScanLanguageAuthorizationTest extends AbstractFunctionalTestCase
{
    private const SITE = 'langscan';
    private const PAGE = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([
            [1, 0, 'Root', '/', 31, 0, 0],
            [self::PAGE, 1, 'Opening hours', '/hours', 31, 0, 0],
            [3, 1, 'Öffnungszeiten', '/hours', 31, 1, self::PAGE],
            [5, 1, 'Locked page', '/locked', 1, 0, 0],
        ] as [$uid, $pid, $title, $slug, $perms, $language, $parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $uid === 1 ? 1 : 0,
                'perms_userid' => 99, 'perms_groupid' => 99, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => $perms,
                'sys_language_uid' => $language, 'l10n_parent' => $parent, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[10, 0, 0], [11, 1, 10]] as [$uid, $language, $parent]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Text ' . $uid, 'sys_language_uid' => $language,
                'l18n_parent' => $parent, 'bodytext' => '<p><a href="/contact"></a></p>', 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);
        foreach ([[10, '0'], [11, '1'], [12, '']] as [$uid, $languages]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'title' => 'Editors ' . $languages, 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => $languages,
            ]);
        }
        foreach ([[1, 1, ''], [2, 0, '10'], [3, 0, '11'], [4, 0, '12']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'user' . $uid, 'admin' => $admin, 'usergroup' => $group, 'disable' => 0, 'deleted' => 0,
            ]);
        }
        // `.invalid` never resolves: the rendered check fails offline, the content check runs.
        $this->get(SiteWriter::class)->write(self::SITE, ['rootPageId' => 1, 'base' => 'https://langscan.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function aTranslationOnlyEditorMayNotScanTheDefaultLanguageButMayScanTheirOwn(): void
    {
        $this->user(3);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(self::PAGE, 0));

        $allowed = $this->scan(self::PAGE, 1);
        self::assertSame(200, $allowed->getStatusCode(), 'positive control: the editor\'s own language');
        self::assertTrue($this->json($allowed)['success']);
        self::assertSame(1, $this->countRows('tx_a11y_scan'));
    }

    #[Test]
    public function aDefaultLanguageEditorMayNotScanATranslationOfAnEditablePage(): void
    {
        $this->user(2);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(self::PAGE, 1));

        $allowed = $this->scan(self::PAGE, 0);
        self::assertSame(200, $allowed->getStatusCode(), 'positive control: the default language');
        self::assertTrue($this->json($allowed)['success']);
        self::assertGreaterThan(0, $this->countRows('tx_a11y_issue'), 'the authorized scan stores its findings');
    }

    #[Test]
    public function allLanguagesNeedAnUnrestrictedUser(): void
    {
        $this->user(2);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(self::PAGE, -1));

        $this->user(4);
        self::assertSame(200, $this->scan(self::PAGE, -1)->getStatusCode(), 'editor without a language restriction');
        $this->user(1);
        self::assertSame(200, $this->scan(self::PAGE, -1)->getStatusCode(), 'administrator');
    }

    /**
     * I-01 (content scan): the uid of a page translation record (3 = German of 2) is no scan target in any language — read as
     * language 0 the rendered check fetched the German page and stored its findings as a default-language scan. Refused for
     * everyone, before any scan state changes; the default page with the language stays the way to scan the translation.
     */
    #[Test]
    public function aTranslationRecordUidIsNoContentScanTarget(): void
    {
        foreach ([2, 4, 1] as $user) {
            $this->user($user);
            // An administrator may edit the locked page 5, so only editors are compared with that refusal.
            $compare = $user !== 1;
            $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(3, 0), $compare);
            $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(3, 1), $compare);
            $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scanSite(3, 0), $compare);
        }
        $this->user(4);
        self::assertSame(200, $this->scan(self::PAGE, 1)->getStatusCode(), 'positive control: the translation through its default page');
    }

    /** S-01 for site content scans: a language the user may not use is refused like for a page scan; their own one is scanned. */
    #[Test]
    public function aSiteContentScanFollowsTheLanguagePolicy(): void
    {
        $this->user(2);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scanSite(1, 1));
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scanSite(1, -1));
        self::assertSame(200, $this->scanSite(1, 0)->getStatusCode(), 'positive control: the default language');
        $this->user(3);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scanSite(1, 0));
        self::assertSame(200, $this->scanSite(1, 1)->getStatusCode(), 'positive control: the editor\'s own language');
    }

    /** A refused language is answered before the running check: no 409, no status of someone else's scan. */
    #[Test]
    public function aRefusedLanguageLearnsNothingAboutARunningScan(): void
    {
        $this->user(1);
        // A running scan holds the scan lock (NEW-06); a status row alone is an interrupted scan, not a running one.
        self::assertNotNull($this->get(ScanStatusService::class)->acquireScanLock());
        $this->get(ScanStatusService::class)->markRunning(trigger: 'page', triggeredBy: 'user1', pageUid: 5, languageUid: 0);

        $this->user(3);
        $refused = $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(self::PAGE, 0));
        self::assertStringNotContainsString('running', strtolower($refused));
        self::assertStringNotContainsString('user1', $refused);

        $this->user(2);
        self::assertSame(409, $this->scan(self::PAGE, 0)->getStatusCode(), 'positive control: an authorized request sees the running scan');
    }

    /** @return string the body of the refusal */
    private function assertRefusedWithoutAnyChange(callable $request, bool $compareWithLockedPage = true): string
    {
        $status = $this->get(ScanStatusService::class)->getStatus();
        $counts = $this->counts();

        $response = $request();
        $body = (string)$response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($status, $this->get(ScanStatusService::class)->getStatus(), 'no scan state change');
        self::assertSame($counts, $this->counts(), 'no scan, finding or source state stored');
        if ($compareWithLockedPage) {
            self::assertSame((string)$this->scan(5, 0)->getBody(), $body, 'answered exactly like a page the user may not edit');
        }
        foreach (['Opening hours', 'Öffnungszeiten', 'contact', 'Deutsch'] as $private) {
            self::assertStringNotContainsString($private, $body);
        }

        return $body;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'scans' => $this->countRows('tx_a11y_scan'),
            'issues' => $this->countRows('tx_a11y_issue'),
            'sourceStates' => $this->countRows('tx_a11y_source_state'),
        ];
    }

    private function scan(int $pageUid, int $languageUid): ResponseInterface
    {
        $request = (new ServerRequest('https://langscan.invalid/typo3/ajax/a11y/scan/page', 'POST'))
            ->withParsedBody(['pageUid' => (string)$pageUid, 'languageUid' => (string)$languageUid])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(ScanAjaxController::class)->scanPageAction($request);
    }

    private function scanSite(int $rootPid, int $languageUid): ResponseInterface
    {
        $request = (new ServerRequest('https://langscan.invalid/typo3/ajax/a11y/scan/site', 'POST'))
            ->withParsedBody(['rootPid' => (string)$rootPid, 'languageUid' => (string)$languageUid])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(ScanAjaxController::class)->scanSiteAction($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }
}
