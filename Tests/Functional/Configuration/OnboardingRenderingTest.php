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
 * The update notice and the first-use / Free Remote Preview guidance rendered with the real Fluid engine and the
 * English and German labels: what a browser and assistive technology receive.
 */
final class OnboardingRenderingTest extends AbstractFunctionalTestCase
{
    private const NOTICE = [
        'version' => '1.9.10',
        'installedVersion' => '1.9.8',
        'releaseNotesUrl' => 'https://typo3.priebera.sk/docs/changelog#v1910',
        'updateInstructionsUrl' => 'https://typo3.priebera.sk/docs/installation',
    ];

    #[Test]
    public function theUpdateNoticeIsOneLabelledLineInEnglishAndGerman(): void
    {
        $xpath = $this->render('Shared/UpdateNotice', ['updateNotice' => self::NOTICE]);

        self::assertSame('AQG 1.9.10 is available', $this->text($xpath, '//*[contains(@class, "aqg-update-notice__title")]'));
        self::assertSame("You're using 1.9.8.", $this->text($xpath, '//*[contains(@class, "aqg-update-notice__installed")]'));
        self::assertSame(
            ["What's new (opens in a new tab)", 'Update instructions (opens in a new tab)'],
            $this->texts($xpath, '//a[contains(@class, "aqg-update-notice__link")]')
        );
        self::assertSame(
            ['https://typo3.priebera.sk/docs/changelog#v1910', 'https://typo3.priebera.sk/docs/installation'],
            array_map(static fn (\DOMElement $link): string => $link->getAttribute('href'), iterator_to_array($xpath->query('//a[contains(@class, "aqg-update-notice__link")]')))
        );
        $dismiss = $xpath->query('//button[@data-action="a11y-dismiss-update-notice"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $dismiss);
        self::assertSame('button', $dismiss->getAttribute('type'));
        self::assertSame('Hide the notice about AQG 1.9.10', $dismiss->getAttribute('aria-label'));
        self::assertSame('1.9.10', $dismiss->getAttribute('data-version'));
        self::assertSame(0, $xpath->query('//*[@role="alert" or @role="status" or @aria-live]')->length);

        $german = $this->render('Shared/UpdateNotice', ['updateNotice' => self::NOTICE], 'de');
        self::assertSame('AQG 1.9.10 ist verfügbar', $this->text($german, '//*[contains(@class, "aqg-update-notice__title")]'));
        self::assertSame('Sie verwenden 1.9.8.', $this->text($german, '//*[contains(@class, "aqg-update-notice__installed")]'));
        self::assertSame(
            ['Neuerungen (öffnet in einem neuen Tab)', 'Anleitung zur Aktualisierung (öffnet in einem neuen Tab)'],
            $this->texts($german, '//a[contains(@class, "aqg-update-notice__link")]')
        );
        self::assertSame(
            'Hinweis zu AQG 1.9.10 ausblenden',
            $german->query('//button[@data-action="a11y-dismiss-update-notice"]')->item(0)?->getAttribute('aria-label')
        );
    }

    #[Test]
    public function withoutANewerReleaseNothingIsRendered(): void
    {
        $xpath = $this->render('Shared/UpdateNotice', ['updateNotice' => null]);

        self::assertSame(0, $xpath->query('//*[@data-aqg-update-notice]')->length);
    }

    #[Test]
    public function theFirstScreenExplainsHowToStart(): void
    {
        $xpath = $this->render('Shared/ModuleEmptyState', ['emptyState' => [
            'title' => 'Select a page to start',
            'body' => 'Choose a page.',
            'stepsTitle' => 'How AQG works',
            'steps' => ['Select a page.', 'Run a content scan.', 'Free Remote Preview.'],
        ]]);

        self::assertSame('How AQG works', $this->text($xpath, '//*[@data-aqg-first-use-steps]/h4'));
        self::assertSame(['Select a page.', 'Run a content scan.', 'Free Remote Preview.'], $this->texts($xpath, '//*[@data-aqg-first-use-steps]//ol/li'));
    }

    #[Test]
    public function aFreeInstallationLearnsTheWholeJourneyWithoutALicence(): void
    {
        $xpath = $this->render('Overview/GettingStarted', [
            'gettingStarted' => ['isFree' => true, 'siteAddressLocal' => false, 'siteHost' => ''],
            'canScanNow' => true,
            'canScanAll' => true,
        ]);

        self::assertSame('Get started with AQG', $this->text($xpath, '//*[contains(@class, "aqg-getting-started__title")]'));
        self::assertSame(
            ['Run a content scan', 'Review the findings', "See what visitors' browsers render"],
            $this->texts($xpath, '//*[contains(@class, "aqg-getting-started__step-title")]')
        );
        $steps = implode(' ', $this->texts($xpath, '//li'));
        self::assertStringContainsString('No licence key is needed.', $steps);
        self::assertStringContainsString('Start it with the scan buttons above.', $steps);
        self::assertStringContainsString('without licence key, account or email', $steps);
        self::assertStringContainsString('The site must be reachable from the internet.', $steps);
        self::assertStringNotContainsString('PRO', $steps);

        $german = $this->render('Overview/GettingStarted', [
            'gettingStarted' => ['isFree' => true, 'siteAddressLocal' => true, 'siteHost' => 'typo314.ddev.site'],
            'canScanNow' => false,
            'canScanAll' => false,
        ], 'de');
        $germanSteps = implode(' ', $this->texts($german, '//li'));
        self::assertStringContainsString('Ein Lizenzschlüssel ist nicht nötig.', $germanSteps);
        self::assertStringContainsString('ohne Lizenzschlüssel, Konto oder E-Mail-Adresse', $germanSteps);
        self::assertStringContainsString('typo314.ddev.site', $germanSteps);
        self::assertStringContainsString('Ihr Konto kann keine Scans starten', $germanSteps);
    }

    #[Test]
    public function aLicensedInstallationIsNotShownTheFreePreview(): void
    {
        $xpath = $this->render('Overview/GettingStarted', [
            'gettingStarted' => ['isFree' => false, 'siteAddressLocal' => false, 'siteHost' => ''],
            'canScanNow' => true,
            'canScanAll' => true,
        ]);

        self::assertSame('licensed', $xpath->query('//*[@data-aqg-getting-started]')->item(0)?->getAttribute('data-aqg-getting-started'));
        self::assertSame(0, $xpath->query('//*[@data-aqg-getting-started-step="free-preview"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-aqg-getting-started-step="frontend-scan"]')->length);
        self::assertStringNotContainsString('Free Remote Preview', (string)$xpath->document->saveHTML());
    }

    #[Test]
    public function aPublicSiteKeepsTheFreeScanAndPreparesTheUnreachableExplanation(): void
    {
        $xpath = $this->render('Overview/FreeRemotePreview', $this->freePreviewArguments([
            'state' => 'FREE_AVAILABLE',
            'available' => true,
            'submitCapable' => true,
            'siteAddressLocal' => false,
            'siteHost' => '',
        ]));

        self::assertSame(1, $xpath->query('//button[@data-aqg-free-preview-submit="true"]')->length);
        self::assertSame(
            'No licence key, account or email needed. The site must be reachable from the internet.',
            $this->text($xpath, '//*[@data-aqg-free-preview-requirements]')
        );
        self::assertSame(0, $xpath->query('//*[@data-aqg-free-preview-message]//*[@data-aqg-free-preview-unreachable]')->length);
        $template = $xpath->query('//template[@data-aqg-free-preview-unreachable-template="true"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $template);
        self::assertStringContainsString('The AQG crawler cannot reach this site', $template->textContent);
        self::assertStringContainsString('Check that the base URL in the Site Configuration', $template->textContent);
        self::assertSame(0, $xpath->query('//*[contains(@class, "aqg-pro-card")]')->length, 'No upgrade offer before a result.');
    }

    #[Test]
    public function theFreeScanButtonNeedsTheScanPermission(): void
    {
        $arguments = $this->freePreviewArguments([
            'state' => 'FREE_AVAILABLE',
            'available' => true,
            'submitCapable' => true,
            'siteAddressLocal' => false,
            'siteHost' => '',
        ]);
        $arguments['canScanNow'] = false;

        $xpath = $this->render('Overview/FreeRemotePreview', $arguments);

        self::assertSame(0, $xpath->query('//button[@data-aqg-free-preview-submit="true"]')->length);
    }

    #[Test]
    public function aLocalSiteGetsAnActionableExplanationInsteadOfAScanButton(): void
    {
        foreach (['en' => ['Free Remote Preview needs a public site address', 'security safeguard, not a licence limit', 'Show content scan'], 'de' => ['Die Free Remote Preview braucht eine öffentliche Website-Adresse', 'Sicherheitsmaßnahme, keine Lizenzbeschränkung', 'Inhaltsscan anzeigen']] as $language => [$title, $why, $action]) {
            $xpath = $this->render('Overview/FreeRemotePreview', $this->freePreviewArguments([
                'state' => 'FREE_AVAILABLE',
                'available' => true,
                'submitCapable' => false,
                'siteAddressLocal' => true,
                'siteHost' => 'typo314.ddev.site',
            ]), $language);

            self::assertSame(0, $xpath->query('//button[@data-aqg-free-preview-submit="true"]')->length, $language);
            self::assertSame(0, $xpath->query('//template')->length, $language);
            $guidance = $xpath->query('//*[@data-aqg-free-preview-unreachable="local"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $guidance, $language);
            $text = trim((string)preg_replace('/\s+/', ' ', $guidance->textContent));
            self::assertStringContainsString($title, $text);
            self::assertStringContainsString('typo314.ddev.site', $text);
            self::assertStringContainsString($why, $text);
            self::assertSame(3, $xpath->query('.//ul/li', $guidance)->length, $language);
            self::assertSame($action, $this->text($xpath, '//button[@data-action="a11y-show-local-scan"]'));
            self::assertSame(0, $xpath->query('//input | //form')->length, 'No URL field or bypass.');
        }
    }

    #[Test]
    public function theNextStepAfterTheFirstContentScanOpensTheFreePreview(): void
    {
        $xpath = $this->render('Overview/FreePreviewNextStep', []);

        self::assertSame('See what your visitors experience', $this->text($xpath, '//*[contains(@class, "aqg-notice__title")]'));
        self::assertSame(
            'Run a free browser-based accessibility scan of this page. No licence key, account or email needed.',
            $this->text($xpath, '//*[contains(@class, "aqg-notice__text")]')
        );
        self::assertSame('Open Free Remote Preview', $this->text($xpath, '//button[@data-action="a11y-open-free-preview"]'));
        self::assertSame(0, $xpath->query('//*[@role]')->length);
    }

    #[Test]
    public function theTrialOfferNamesOnlyRealPlanCapabilities(): void
    {
        $xpath = $this->render('Overview/FreePreviewUpgrade', ['freePreview' => [
            'trialUrl' => 'https://typo3.priebera.sk/trial?utm_source=typo3_backend',
            'upgradeUrl' => 'https://typo3.priebera.sk/pricing?utm_source=typo3_backend',
        ]]);

        self::assertSame('Check the whole site, not just this page', $this->text($xpath, '//h3'));
        $text = $this->text($xpath, '//*[contains(@class, "aqg-pro-card__text")]');
        foreach (['5-day trial', 'full-site frontend scans', 'screenshots', 'scan history and comparison', 'PDF export needs PRO or Agency', 'acceptance evidence', 'monitoring', 'Verify fix'] as $capability) {
            self::assertStringContainsString($capability, $text);
        }
        self::assertStringNotContainsString('PRO trial', $text);
        $trial = $xpath->query('//a[contains(@class, "btn-primary")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $trial);
        self::assertSame('https://typo3.priebera.sk/trial?utm_source=typo3_backend', $trial->getAttribute('href'));
        self::assertSame('Start 5-day trial (opens in a new tab)', trim((string)preg_replace('/\s+/', ' ', $trial->textContent)));
        self::assertSame('noopener noreferrer', $trial->getAttribute('rel'));

        $german = $this->render('Overview/FreePreviewUpgrade', ['freePreview' => ['trialUrl' => '#', 'upgradeUrl' => '#']], 'de');
        self::assertStringContainsString('5-tägige Testphase', $this->text($german, '//*[contains(@class, "aqg-pro-card__text")]'));
        self::assertSame('5-tägige Testphase starten (öffnet in einem neuen Tab)', $this->text($german, '//a[contains(@class, "btn-primary")]'));
    }

    /**
     * @param array<string, mixed> $freePreview
     * @return array<string, mixed>
     */
    private function freePreviewArguments(array $freePreview): array
    {
        return [
            'freePreview' => $freePreview + [
                'isFree' => true,
                'entitlement' => 'free_daily',
                'jobsUsed' => 1,
                'jobsLimit' => 5,
                'resetsAt' => '2026-10-04T00:00:00Z',
                'message' => '',
                'retryable' => false,
                'hasTodayResult' => false,
            ],
            'freeSubmitIntent' => 'intent-token',
            'canScanNow' => true,
            'siteRootPid' => 1,
            'currentPageUid' => 2,
            'siteIdentifier' => 'main',
            'remoteScan' => null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(string $partial, array $arguments, string $language = 'default'): \DOMXPath
    {
        // Fluid's f:translate takes the backend language from the backend user's preferences.
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'lang' => $language === 'default' ? '' : $language];
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/'),
            ],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => $partial, 'arguments' => $arguments]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function text(\DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)->item(0);
        self::assertNotNull($node, 'Not rendered: ' . $query);

        return trim((string)preg_replace('/\s+/', ' ', $node->textContent));
    }

    /**
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) as $node) {
            $texts[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }
}
