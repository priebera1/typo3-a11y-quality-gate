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
 * The frontend page detail explains findings that are not linked to a TYPO3 record because the site has no Scanner
 * Token, once per page and with the way to fix it.
 */
final class RecordMappingNoticeRenderingTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aSiteWithoutScannerTokenSaysWhyAndWhereToFixIt(): void
    {
        $xpath = $this->render(['recordMappingNotice' => [
            'show' => true, 'scannerTokenMissing' => true, 'unmappedNodes' => 3, 'totalNodes' => 14, 'allUnmapped' => false,
            'settingsUrl' => '/typo3/module/a11y/settings?tab=remote_access',
        ]]);

        $notice = $xpath->query('//*[@data-aqg-record-mapping-notice="no-scanner-token"]')->item(0);
        self::assertNotNull($notice);
        $text = trim((string)preg_replace('/\s+/', ' ', $notice->textContent));
        self::assertStringContainsString('no Scanner Token', $text);
        // Some occurrences are linked: the headline names the share instead of "findings are not linked".
        self::assertStringContainsString('3 of 14 occurrences are not linked to a TYPO3 record.', $text);
        self::assertStringNotContainsString('No occurrence on this page', $text);
        // Information, not a warning: an unlinked finding is still a valid finding.
        self::assertStringContainsString('aqg-note--info', (string)$notice->getAttribute('class'));
        self::assertSame('/typo3/module/a11y/settings?tab=remote_access', $xpath->query('.//a', $notice)->item(0)?->getAttribute('href'));
    }

    #[Test]
    public function aPageWithoutAnyLinkedOccurrenceSaysNoneIsLinked(): void
    {
        $xpath = $this->render(['recordMappingNotice' => [
            'show' => true, 'scannerTokenMissing' => true, 'unmappedNodes' => 4, 'totalNodes' => 4, 'allUnmapped' => true, 'settingsUrl' => '',
        ]]);

        $text = trim((string)preg_replace('/\s+/', ' ', (string)$xpath->query('//*[@data-aqg-record-mapping-notice]')->item(0)?->textContent));
        self::assertStringContainsString('No occurrence on this page is linked to a TYPO3 record.', $text);
    }

    #[Test]
    public function editorsWithoutSettingsAccessGetTheReasonWithoutALink(): void
    {
        $xpath = $this->render(['recordMappingNotice' => [
            'show' => true, 'scannerTokenMissing' => true, 'unmappedNodes' => 1, 'settingsUrl' => '',
        ]]);

        self::assertSame(1, $xpath->query('//*[@data-aqg-record-mapping-notice]')->length);
        self::assertSame(0, $xpath->query('//*[@data-aqg-record-mapping-notice]//a')->length);
    }

    #[Test]
    public function nothingIsRenderedWhenTheSiteHasAToken(): void
    {
        $xpath = $this->render(['recordMappingNotice' => [
            'show' => false, 'scannerTokenMissing' => false, 'unmappedNodes' => 2, 'settingsUrl' => '/x',
        ]]);

        self::assertSame(0, $xpath->query('//*[@data-aqg-record-mapping-notice]')->length);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(array $arguments): \DOMXPath
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'lang' => ''];
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => 'Remote/RecordMappingNotice', 'arguments' => $arguments]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
