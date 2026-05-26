<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\RenderedCheckNonceService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;

final class RenderedPageUrlResolver
{
    public function __construct(
        private readonly SiteResolutionService $siteResolutionService,
        private readonly FrontendPageUrlService $frontendPageUrlService,
        private readonly RenderedCheckNonceService $renderedCheckNonceService,
    ) {
    }

    public function resolve(int $pageUid, int $languageUid): ?RenderedPageUrl
    {
        if ($pageUid <= 0 || $languageUid < 0) {
            return null;
        }

        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if ($site === null) {
            return null;
        }

        $url = trim($this->frontendPageUrlService->resolveForPage($site, $pageUid, $languageUid));
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            $url = rtrim((string)$site->getBase(), '/') . $url;
        }

        $url = $this->appendRenderedCheckParameter($url, $pageUid, $languageUid);
        $siteBase = (string)$site->getBase();
        $allowedHost = strtolower((string)(parse_url($siteBase, PHP_URL_HOST) ?: parse_url($url, PHP_URL_HOST) ?: ''));
        if ($allowedHost === '') {
            return null;
        }

        $allowedPort = parse_url($siteBase, PHP_URL_PORT);
        if (!is_int($allowedPort)) {
            $allowedPort = parse_url($url, PHP_URL_PORT);
        }
        $allowedPort = is_int($allowedPort) ? $allowedPort : null;

        return new RenderedPageUrl(
            url: $url,
            allowedHost: $allowedHost,
            allowedPort: $allowedPort,
            siteIdentifier: $site->getIdentifier(),
        );
    }

    private function appendRenderedCheckParameter(string $url, int $pageUid, int $languageUid): string
    {
        $nonce = $this->renderedCheckNonceService->generate($pageUid, $languageUid);
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query([
            'aqgDebug' => '1',
            'tx_aqg_rendered_check' => '1',
            'no_cache' => '1',
            '_aqg_page' => $pageUid,
            '_aqg_lang' => $languageUid,
            '_aqg_nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
