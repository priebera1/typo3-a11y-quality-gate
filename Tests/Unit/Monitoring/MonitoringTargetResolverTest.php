<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Monitoring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Monitoring\InvalidMonitoringTargetException;

/**
 * The Scheduler choices come from the site configuration, and a run only starts on a site and one of its
 * enabled languages.
 */
final class MonitoringTargetResolverTest extends TestCase
{
    use MonitoringFixtures;

    #[Test]
    public function everyConfiguredSiteIsOfferedWithTitleIdentifierAndRootPage(): void
    {
        $choices = $this->resolverFor()->siteChoices();

        self::assertSame(['main', 'shop'], array_column($choices, 'identifier'), 'Sorted by site title.');
        self::assertSame('Example (main), root page 1', $choices[0]['label']);
        self::assertSame('Shop (shop), root page 20', $choices[1]['label']);
    }

    #[Test]
    public function theLanguagesAreTheEnabledLanguagesOfThatSiteWithReadableLabels(): void
    {
        $resolver = $this->resolverFor();

        $main = $resolver->languageChoices(self::sites()['main']);
        self::assertSame([0, 2], array_column($main, 'languageId'), 'Real language IDs; the disabled language 3 has no frontend to scan.');
        self::assertStringStartsWith('English (en', $main[0]['label']);
        self::assertStringEndsWith('– default language', $main[0]['label']);
        self::assertStringStartsWith('Deutsch (de', $main[1]['label']);
        self::assertStringNotContainsString('default', $main[1]['label']);

        self::assertCount(1, $resolver->languageChoices(self::sites()['shop']), 'A site with one language offers exactly that one.');
    }

    #[Test]
    public function aConfiguredSiteAndOneOfItsLanguagesResolve(): void
    {
        self::assertSame('main', $this->resolverFor()->resolve(' main ', 2)->getIdentifier());
    }

    #[Test]
    public function aSiteThatIsNoLongerConfiguredIsReportedByName(): void
    {
        $this->expectException(InvalidMonitoringTargetException::class);
        $this->expectExceptionCode(InvalidMonitoringTargetException::SITE_MISSING);
        $this->expectExceptionMessage('The site "retired" is not configured');

        $this->resolverFor()->resolve('retired', 0);
    }

    #[Test]
    public function aLanguageTheSiteDoesNotHaveIsReportedInsteadOfScanningTheDefaultPages(): void
    {
        foreach ([1, 3] as $languageUid) {
            try {
                $this->resolverFor()->resolve('main', $languageUid);
                self::fail('Language ' . $languageUid . ' must not resolve.');
            } catch (InvalidMonitoringTargetException $exception) {
                self::assertSame(InvalidMonitoringTargetException::LANGUAGE_MISSING, $exception->getCode());
                self::assertStringContainsString('Language ' . $languageUid . ' is not an enabled language of the site "main"', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function anEmptySiteAsksForASite(): void
    {
        $this->expectException(InvalidMonitoringTargetException::class);
        $this->expectExceptionCode(InvalidMonitoringTargetException::SITE_REQUIRED);

        $this->resolverFor()->resolve('', 0);
    }
}
