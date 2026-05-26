<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Psr\Log\LoggerInterface;

final class RenderedPageScanner
{
    public function __construct(
        private readonly RenderedPageUrlResolver $urlResolver,
        private readonly RenderedPageFetcher $pageFetcher,
        private readonly RenderedHtmlAnalyzer $htmlAnalyzer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return RuleViolation[]
     */
    public function scanPage(string $siteIdentifier, int $pageUid, int $languageUid, bool $allowPrivateHosts = false): array
    {
        return $this->scanPageWithResult($siteIdentifier, $pageUid, $languageUid, $allowPrivateHosts)->violations;
    }

    public function scanPageWithResult(string $siteIdentifier, int $pageUid, int $languageUid, bool $allowPrivateHosts = false): RenderedPageScanResult
    {
        if ($languageUid < 0) {
            $languageUid = 0;
        }

        $pageUrl = $this->urlResolver->resolve($pageUid, $languageUid);
        if ($pageUrl === null) {
            $this->logger->warning('Rendered page check skipped: frontend URL could not be resolved.', [
                'siteIdentifier' => $siteIdentifier,
                'pageUid' => $pageUid,
                'languageUid' => $languageUid,
            ]);
            return new RenderedPageScanResult(false, warning: 'Frontend URL could not be resolved.');
        }

        $response = $this->pageFetcher->fetch(
            $pageUrl->url,
            $pageUrl->allowedHost,
            $pageUrl->allowedPort,
            $allowPrivateHosts
        );
        if (!$response->success) {
            $this->logger->warning('Rendered page check skipped: fetch failed.', [
                'siteIdentifier' => $siteIdentifier,
                'pageUid' => $pageUid,
                'languageUid' => $languageUid,
                'url' => $pageUrl->url,
                'allowPrivateHosts' => $allowPrivateHosts,
                'statusCode' => $response->statusCode,
                'error' => $response->error,
            ]);
            return new RenderedPageScanResult(false, warning: $response->error);
        }

        $violations = $this->htmlAnalyzer->analyze(
            pageUid: $pageUid,
            languageUid: $languageUid,
            siteIdentifier: $siteIdentifier,
            url: $response->finalUrl !== '' ? $response->finalUrl : $pageUrl->url,
            html: $response->html,
        );

        return new RenderedPageScanResult(true, $violations);
    }
}
