<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Command\ScanCommand;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * ML-04 (2026-11-12 multilingual acceptance): `--language=de` was cast to 0 and scanned English, a language the site
 * does not have "completed" and stored findings in it, and `--language=-5` was stored as a scan language. Like the
 * Scheduler task (U-06), the CLI accepts only `all` or a language of the page's site, and refuses anything else before
 * a scan starts.
 */
final class ScanCommandLanguageOptionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function refusedLanguages(): iterable
    {
        yield 'language code instead of an id' => ['de'];
        yield 'negative id' => ['-5'];
        yield 'not a language of the site' => ['99'];
        yield 'decimal' => ['4.5'];
    }

    #[Test]
    #[DataProvider('refusedLanguages')]
    public function anUnusableLanguageIsRefusedBeforeAnyScan(string $language): void
    {
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::never())->method('scanPage');
        $scans->expects(self::never())->method('scanSubtree');
        $locks = $this->createMock(ScanStatusService::class);
        $locks->expects(self::never())->method('acquireScanLock');

        $tester = new CommandTester(new ScanCommand($scans, $this->sites(), $locks));
        $status = $tester->execute(['--page-uid' => '42', '--language' => $language]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('language', strtolower($tester->getDisplay()));
    }

    /** @return iterable<string, array{string, int}> */
    public static function acceptedLanguages(): iterable
    {
        yield 'all' => ['all', -1];
        yield 'ALL' => ['ALL', -1];
        yield 'minus one (all)' => ['-1', -1];
        yield 'bare option (default: all)' => ['', -1];
        yield 'default' => ['0', 0];
        yield 'German' => ['4', 4];
    }

    #[Test]
    #[DataProvider('acceptedLanguages')]
    public function allOrALanguageOfTheSiteIsScanned(string $language, int $expected): void
    {
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanSubtree')
            ->with('main', 42, 99, $expected, false, self::anything())
            ->willReturn(new ScanResult(7));
        $locks = $this->createMock(ScanStatusService::class);
        $locks->method('acquireScanLock')->willReturn('lock');

        $tester = new CommandTester(new ScanCommand($scans, $this->sites(), $locks));

        self::assertSame(Command::SUCCESS, $tester->execute(['--root-pid' => '42', '--language' => $language]));
    }

    #[Test]
    public function aPageOutsideEverySiteAcceptsOnlyAllOrTheDefaultLanguage(): void
    {
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::never())->method('scanPage');
        $locks = $this->createMock(ScanStatusService::class);
        $locks->expects(self::never())->method('acquireScanLock');
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByPageId')->willReturn(null);

        $tester = new CommandTester(new ScanCommand($scans, $sites, $locks));

        self::assertSame(Command::FAILURE, $tester->execute(['--page-uid' => '42', '--language' => '3']));
        self::assertStringContainsString('belongs to no site', $tester->getDisplay());
    }

    private function sites(): SiteResolutionService
    {
        $site = new Site('main', 42, [
            'base' => 'https://example.org/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/'],
                ['languageId' => 4, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ]);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByPageId')->willReturn($site);
        $sites->method('resolveSiteIdentifierFromPageId')->willReturn('main');

        return $sites;
    }
}
