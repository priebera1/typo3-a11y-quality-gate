<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Rule\RuleViolation;
use Psr\Log\LoggerInterface;

final class RenderedPageScanner
{
    public function __construct(
        private readonly RenderedPageUrlResolver $urlResolver,
        private readonly RenderedPageFetcher $pageFetcher,
        private readonly RenderedErrorPageDetector $errorPageDetector,
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

        $errorPageDetection = $this->errorPageDetector->detect($response->html);
        if ($errorPageDetection->suspectedErrorPage) {
            $this->logger->warning('Rendered check received suspected error page.', [
                'siteIdentifier' => $siteIdentifier,
                'pageUid' => $pageUid,
                'languageUid' => $languageUid,
                'url' => $response->finalUrl !== '' ? $response->finalUrl : $pageUrl->url,
                'statusCode' => $response->statusCode,
                'contentType' => $response->contentType,
                'htmlLength' => strlen($response->html),
                'htmlPreviewHash' => hash('sha256', substr($response->html, 0, 500)),
                'detectedPattern' => $errorPageDetection->reason,
            ]);

            return new RenderedPageScanResult(
                false,
                warning: 'Rendered page check received an error page instead of the expected frontend HTML. Check frontend rendering, middleware, external dependencies or use the PRO remote crawler for browser-based scanning.',
                failureReason: 'error_page'
            );
        }

        $h1Count = $this->countRenderedH1Elements($response->html);
        $violations = $this->htmlAnalyzer->analyze(
            pageUid: $pageUid,
            languageUid: $languageUid,
            siteIdentifier: $siteIdentifier,
            url: $response->finalUrl !== '' ? $response->finalUrl : $pageUrl->url,
            html: $response->html,
        );

        return new RenderedPageScanResult(true, $violations, h1Count: $h1Count);
    }

    private function countRenderedH1Elements(string $html): int
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET)) {
                return 0;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//h1[not(ancestor::template)]');

        return $nodes instanceof \DOMNodeList ? $nodes->length : 0;
    }
}
