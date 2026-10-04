<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first-use journey: content scan without a licence, then the Free Remote Preview, then an optional trial.
 * Rendering with real labels is covered by OnboardingRenderingTest; this pins the conditions that decide who sees
 * which step.
 */
final class FirstUseOnboardingTemplateTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    #[Test]
    public function contentScanActionsAreNeverGatedByALicence(): void
    {
        $actions = $this->read('Resources/Private/Partials/Overview/LocalActions.html');

        self::assertStringContainsString('<f:if condition="{currentPageUid} && {canScanNow}">', $actions);
        self::assertStringContainsString('<f:if condition="{canScanAll}">', $actions);
        self::assertStringContainsString('data-action="a11y-rescan"', $actions);
        self::assertStringContainsString('data-action="a11y-scan-all"', $actions);
        self::assertStringNotContainsString('proStatus', $actions);
        self::assertStringNotContainsString('freePreview', $actions);
        self::assertStringContainsString('partial="Overview/GettingStarted"', $actions);
    }

    #[Test]
    public function theGettingStartedGuideOffersTheFreePreviewOnlyWithoutALicence(): void
    {
        $guide = $this->read('Resources/Private/Partials/Overview/GettingStarted.html');

        self::assertMatchesRegularExpression(
            '/<f:if condition="\{gettingStarted\.isFree\}">\s*<f:then>.*overview\.gettingStarted\.freePreview\.title.*<\/f:then>\s*<f:else>.*overview\.gettingStarted\.frontendScan\.title.*<\/f:else>/s',
            $guide
        );
        self::assertStringContainsString('gettingStarted.siteAddressLocal', $guide);

        $controller = $this->normalized('Classes/Controller/OverviewController.php');
        self::assertStringContainsString("\$gettingStarted = \$lastScan === null && \$totalLocalPages === 0 ? [ 'isFree' => \$isFreePreview,", $controller);
    }

    #[Test]
    public function theNextStepAppearsOnceAfterTheFirstContentScanAndOnlyForFreeInstallations(): void
    {
        $controller = $this->normalized('Classes/Controller/OverviewController.php');

        foreach ([
            '$showFreePreviewNextStep = $isFreePreview',
            '&& $lastScan !== null',
            '&& $licenceNotice === null',
            '&& $canScanNow',
            "&& (bool)\$freePreview['submitCapable']",
            "&& (string)(\$freePreview['state'] ?? '') === 'FREE_AVAILABLE'",
            '&& !is_array($remoteScan)',
            '&& $this->remoteScanRepository->findLastCompletedPageScanBySite($siteIdentifier, -1, true) === null;',
        ] as $condition) {
            self::assertStringContainsString($condition, $controller);
        }

        $panel = $this->read('Resources/Private/Partials/Overview/LocalPanel.html');
        self::assertStringContainsString("<f:if condition=\"{showFreePreviewNextStep}\">\n        <f:render partial=\"Overview/FreePreviewNextStep\" />", $panel);
        $nextStep = $this->read('Resources/Private/Partials/Overview/FreePreviewNextStep.html');
        self::assertStringContainsString('data-action="a11y-open-free-preview"', $nextStep);
        self::assertStringNotContainsString('role=', $nextStep);
    }

    #[Test]
    public function aLocalSiteGetsGuidanceInsteadOfAScanButton(): void
    {
        $controller = $this->normalized('Classes/Controller/OverviewController.php');
        self::assertStringContainsString("\$freePreview['submitCapable'] = (bool)(\$freePreview['available'] ?? false) && \$freeSubmitIntent !== '' && \$nonPublicSiteHost === '';", $controller);
        self::assertStringContainsString('if ($siteBase === \'\' || ProSettings::usesCustomServiceEndpoint())', $controller);

        $free = $this->read('Resources/Private/Partials/Overview/FreeRemotePreview.html');
        self::assertStringContainsString('<f:if condition="{freePreview.submitCapable} && {freePreview.state} == \'FREE_AVAILABLE\' && {canScanNow}">', $free);
        self::assertStringContainsString("arguments=\"{variant: 'local', siteHost: freePreview.siteHost}\"", $free);
        self::assertStringContainsString('<template data-aqg-free-preview-unreachable-template="true">', $free);
        self::assertStringContainsString('freePreview.requirements', $free);

        // The guidance never offers a way around the crawler: no URL field, no override, no allowlist.
        $guidance = $this->read('Resources/Private/Partials/Overview/FreePreviewUnreachable.html');
        foreach (['<input', '<form', 'allowPrivate', 'data-page-url', 'data-start-url'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $guidance);
            self::assertStringNotContainsString($forbidden, $free);
        }
    }

    #[Test]
    public function theProtectionStaysWithTheCrawler(): void
    {
        // The Free submit still sends no URL of the browser's choosing, and the classifier only hides the button.
        $submit = $this->read('Resources/Public/JavaScript/backend/pro/pro-module.js');
        self::assertMatchesRegularExpression(
            '/const payload = isFreePreview\s*\?\s*\{\s*rootPid,\s*pageUid: [^,]+,\s*siteIdentifier,\s*languageUid,\s*freeSubmitIntent,\s*\}/',
            $submit
        );
        self::assertStringContainsString("'private_network_blocked',", $submit);
        self::assertStringContainsString("'dns_lookup_failed',", $submit);

        $classifier = $this->read('Classes/FreePreview/PublicSiteAddressClassifier.php');
        foreach (['dns_get_record', 'gethostbyname', 'RequestFactory', 'curl_'] as $networkCall) {
            self::assertStringNotContainsString($networkCall, $classifier);
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($content, $path);

        return $content;
    }

    private function normalized(string $path): string
    {
        return (string)preg_replace('/\s+/', ' ', $this->read($path));
    }
}
