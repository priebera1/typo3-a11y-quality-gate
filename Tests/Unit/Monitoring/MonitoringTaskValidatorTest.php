<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Monitoring;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Monitoring\MonitoringTaskValidator;

final class MonitoringTaskValidatorTest extends TestCase
{
    use MonitoringFixtures;

    #[Test]
    public function aCompleteConfigurationIsAccepted(): void
    {
        self::assertSame([], $this->validator()->validate('main', 2, 'qa@example.org, web@example.org', 500, 1200, 'https://cms.example.org', true));
        self::assertSame([], $this->validator()->validate('shop', 0, 'qa@example.org', 1, 0, '', true));
    }

    #[Test]
    public function aFinalSubmitNeedsASiteALanguageOfThatSiteAndARecipient(): void
    {
        $errors = $this->validator()->validate('', 0, '', 500, 1200, '', true);
        self::assertContains('Choose the site to monitor.', $errors);
        self::assertContains('Enter at least one e-mail address that receives the notifications.', $errors);

        self::assertSame(
            ['Language 2 is not an enabled language of the site "shop". Choose one of the site\'s languages.'],
            $this->validator()->validate('shop', 2, 'qa@example.org', 500, 1200, '', true)
        );
    }

    #[Test]
    public function aFormReloadOnlyChecksWhatWasEntered(): void
    {
        // TYPO3 14 saves when the site changes, before a language of that site or recipients could be chosen.
        self::assertSame([], $this->validator()->validate('', 0, '', 500, 1200, '', false));
        self::assertSame([], $this->validator()->validate('shop', 2, '', 500, 1200, '', false));
        self::assertSame(
            ['The site "retired" is not configured in this TYPO3 installation. Choose a configured site.'],
            $this->validator()->validate('retired', 0, '', 500, 1200, '', false)
        );
    }

    #[Test]
    public function everyRecipientMustBeAnEmailAddress(): void
    {
        self::assertSame(
            ['"not-an-address" is not a valid e-mail address.'],
            $this->validator()->validate('main', 0, 'qa@example.org; not-an-address', 500, 1200, '', true)
        );
    }

    /**
     * @return iterable<string, array{0:int,1:int,2:string}>
     */
    public static function outOfRange(): iterable
    {
        yield 'no pages' => [0, 1200, 'Maximum pages per scan must be between 1 and 1000.'];
        yield 'too many pages' => [1001, 1200, 'Maximum pages per scan must be between 1 and 1000.'];
        yield 'negative wait' => [500, -1, 'The waiting time must be between 0 and 3600 seconds.'];
        yield 'wait over an hour' => [500, 3601, 'The waiting time must be between 0 and 3600 seconds.'];
    }

    #[DataProvider('outOfRange')]
    #[Test]
    public function limitsOutsideTheCommandRangesAreRefused(int $maxPages, int $maxWait, string $message): void
    {
        self::assertSame([$message], $this->validator()->validate('main', 0, 'qa@example.org', $maxPages, $maxWait, '', true));
    }

    #[Test]
    public function theBackendAddressIsSchemeAndHostOnly(): void
    {
        foreach (['https://cms.example.org', 'https://cms.example.org/', 'http://localhost:8080'] as $url) {
            self::assertTrue(MonitoringTaskValidator::isBackendBaseUrl($url), $url);
        }
        foreach (['cms.example.org', 'ftp://cms.example.org', 'https://cms.example.org/typo3', 'https://cms.example.org/?x=1', 'https://user:pass@cms.example.org', 'javascript:alert(1)'] as $url) {
            self::assertFalse(MonitoringTaskValidator::isBackendBaseUrl($url), $url);
        }
    }

    #[Test]
    public function recipientsAreSplitOnCommasSemicolonsAndLineBreaks(): void
    {
        self::assertSame(
            ['a@example.org', 'b@example.org', 'c@example.org'],
            MonitoringTaskValidator::parseRecipients(" a@example.org,b@example.org;\nc@example.org, a@example.org ")
        );
    }

    private function validator(): MonitoringTaskValidator
    {
        return new MonitoringTaskValidator($this->resolverFor());
    }
}
