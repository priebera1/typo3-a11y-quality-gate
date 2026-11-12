<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;

/**
 * Atlas audit F-16: the German backend showed "1 Dec 2026", "December 2026", "1,332" and "$25.00". Dates, numbers and
 * USD amounts follow the backend user's language; English keeps AQG's established output.
 */
final class BackendLocaleFormatterTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    private const DEC_1_2026_NOON = 1796122800; // 2026-12-01 12:00 Europe/Berlin

    #[Test]
    public function englishKeepsTheEstablishedFormats(): void
    {
        $en = new BackendLocaleFormatter('en');

        self::assertTrue($en->isEnglish());
        self::assertSame('1 Dec 2026', $en->date(self::DEC_1_2026_NOON));
        self::assertSame('1 Dec 2026, 12:00', $en->dateTime(self::DEC_1_2026_NOON));
        self::assertSame('December 2026', $en->monthYear(self::DEC_1_2026_NOON));
        self::assertSame('1,332', $en->integer(1332));
        self::assertSame(',', $en->thousandsSeparator());
        self::assertSame('$25.00', $en->usd(25_000_000));
        self::assertSame('< $0.01', $en->usd(4_000));
        self::assertSame('$0.075', $en->usdPrice(75_000));
        self::assertSame('', $en->date(0));
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function germanUsesGermanDatesNumbersAndCurrencyPlacement(): void
    {
        $de = new BackendLocaleFormatter('de');

        self::assertFalse($de->isEnglish());
        self::assertSame('01.12.2026', $de->date(self::DEC_1_2026_NOON));
        self::assertSame('01.12.2026, 12:00', $de->dateTime(self::DEC_1_2026_NOON));
        self::assertSame('Dezember 2026', $de->monthYear(self::DEC_1_2026_NOON));
        self::assertSame('1.332', $de->integer(1332));
        self::assertSame('.', $de->thousandsSeparator());
        self::assertSame("25,00\u{00A0}$", $de->usd(25_000_000));
        self::assertSame("1.250,50\u{00A0}$", $de->usd(1_250_500_000));
        self::assertSame("< 0,01\u{00A0}$", $de->usd(4_000));
        self::assertSame("0,075\u{00A0}$", $de->usdPrice(75_000));
        self::assertSame("2,00\u{00A0}$", $de->usdPrice(2_000_000));
        foreach ([$de->date(self::DEC_1_2026_NOON), $de->monthYear(self::DEC_1_2026_NOON)] as $label) {
            self::assertStringNotContainsString('Dec', $label);
        }
    }

    #[Test]
    public function withoutBackendLanguageTheFormatterIsEnglish(): void
    {
        unset($GLOBALS['LANG']);

        self::assertTrue(BackendLocaleFormatter::forBackendUser()->isEnglish());
        self::assertTrue((new BackendLocaleFormatter('default'))->isEnglish());
    }

    #[Test]
    public function oneCentBoundCarriesNoApproximationSign(): void
    {
        self::assertTrue(BackendLocaleFormatter::isBelowOneCent(1));
        self::assertTrue(BackendLocaleFormatter::isBelowOneCent(9_999));
        self::assertFalse(BackendLocaleFormatter::isBelowOneCent(0));
        self::assertFalse(BackendLocaleFormatter::isBelowOneCent(10_000));
    }
}
