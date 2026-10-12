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
 * The scan progress blocks offer Cancel only to users the cancel endpoints accept (ScanPermissionService).
 *
 * Regression guard: a view-only user watching another user's scan got a Cancel button that answered 403.
 */
final class ScanProgressCancelRenderingTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theContentScanProgressOffersCancelOnlyWhenAllowed(): void
    {
        $arguments = ['isRunning' => true, 'scanStatus' => ['running' => true, 'pageUid' => 2, 'rootPid' => 1]];

        self::assertSame(1, $this->cancelButtons('Shared/LocalScanProgress', $arguments + ['canCancel' => true], 'a11y-local-cancel-scan'));
        self::assertSame(0, $this->cancelButtons('Shared/LocalScanProgress', $arguments + ['canCancel' => false], 'a11y-local-cancel-scan'));
    }

    #[Test]
    public function theFrontendScanProgressOffersCancelOnlyWhenAllowed(): void
    {
        $arguments = ['activeRemoteScan' => ['job_id' => 'job-1', 'site_identifier' => 'main', 'status' => 'active', 'is_free_preview' => 0]];

        self::assertSame(1, $this->cancelButtons('Shared/RemoteScanProgress', $arguments + ['canCancel' => true], 'a11y-pro-cancel-scan'));
        self::assertSame(0, $this->cancelButtons('Shared/RemoteScanProgress', $arguments + ['canCancel' => false], 'a11y-pro-cancel-scan'));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function cancelButtons(string $partial, array $arguments, string $action): int
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'lang' => ''];
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => $partial, 'arguments' => $arguments]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))->query('//button[@data-action="' . $action . '"]')->length;
    }
}
