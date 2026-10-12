<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The Trial line of the frontend scan status, rendered with the real Fluid engine.
 *
 * Regression guard: a Trial only ever saw "Max 5 scans in the last 24 hours", never how many of them it had used, so it learned
 * about the limit from a rejected scan.
 */
final class TrialLimitsRenderingTest extends AbstractFunctionalTestCase
{
    private const USAGE = [
        'limitsEnforced' => true,
        'available' => true,
        'jobsUsed' => 2,
        'jobsLimit' => 5,
        'scansRemaining' => 3,
        'maxPagesPerJob' => 10,
        'nextScanAt' => '',
        'nextScanAtFormatted' => '',
    ];

    #[Test]
    public function itShowsTheDailyUsageAndWhatIsLeftBesideThePageLimit(): void
    {
        $pills = $this->pills(['trialUsage' => self::USAGE]);

        self::assertSame(
            ['TRIAL', 'Max 10 pages per frontend scan', '2 of 5 scans used in the last 24 hours', 'Scans left: 3'],
            $pills,
        );
    }

    #[Test]
    public function aUsedUpAllowanceSaysWhenTheNextScanIsPossible(): void
    {
        $pills = $this->pills(['trialUsage' => [
            'available' => false,
            'jobsUsed' => 5,
            'scansRemaining' => 0,
            'nextScanAt' => '2026-10-08T08:00:00.000Z',
            'nextScanAtFormatted' => '08.10.2026 10:00',
        ] + self::USAGE]);

        self::assertContains('5 of 5 scans used in the last 24 hours', $pills);
        self::assertContains('No scans left until 08.10.2026 10:00', $pills);
    }

    #[Test]
    public function withoutEnforcementNoUsageIsCounted(): void
    {
        $pills = $this->pills(['trialUsage' => ['limitsEnforced' => false] + self::USAGE]);

        self::assertSame(['TRIAL', 'Max 10 pages per frontend scan', 'No 24-hour scan limit applies to this installation'], $pills);
    }

    #[Test]
    public function withoutAnAnswerFromTheApiItShowsThePlanLimitsOnly(): void
    {
        self::assertSame(
            ['TRIAL', 'Max 10 pages per frontend scan', 'Max 5 scans in the last 24 hours'],
            $this->pills(['trialUsage' => null]),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    private function pills(array $arguments): array
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'lang' => ''];
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => 'Overview/TrialLimits', 'arguments' => $arguments]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $pills = [];
        foreach ((new \DOMXPath($document))->query('//*[@data-aqg-trial]') as $node) {
            $pills[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $pills;
    }
}
