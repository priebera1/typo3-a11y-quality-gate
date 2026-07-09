<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionContext;
use Priebera\A11yQualityGate\Ai\Exception\AiIframeTitleSuggestionException;
use Priebera\A11yQualityGate\Contract\BackendRecordAccessServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;

final class AiIframeTitleSuggestionContextResolver
{
    public const SUPPORTED_RULE_ID = 'rendered.iframe_missing_title';

    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly BackendRecordAccessServiceInterface $recordAccessService,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function resolve(int $findingId): AiIframeTitleSuggestionContext
    {
        $issue = $this->issueRepository->findByUid($findingId);
        if (!is_array($issue)) {
            throw new AiIframeTitleSuggestionException('finding_not_found', 'The requested finding could not be found.', 1771002920);
        }

        $ruleId = trim((string)($issue['rule_id'] ?? ''));
        if ($ruleId !== self::SUPPORTED_RULE_ID) {
            throw new AiIframeTitleSuggestionException('unsupported_rule', 'AI iframe-title suggestions are not available for this rule.', 1771002921);
        }

        $pageUid = (int)($issue['page_uid'] ?? 0);
        if ($pageUid <= 0) {
            throw new AiIframeTitleSuggestionException('unsupported_context', 'The rendered iframe finding is not connected to a page.', 1771002922);
        }

        $sourceTable = trim((string)($issue['source_table'] ?? ''));
        $sourceUid = (int)($issue['source_uid'] ?? 0);
        if ($sourceTable === '' || $sourceUid <= 0) {
            $sourceTable = Tables::PAGES;
            $sourceUid = $pageUid;
        }

        if (!$this->recordAccessService->isRecordOnPage($sourceTable, $sourceUid, $pageUid)
            || !$this->recordAccessService->canEditRecord($sourceTable, $sourceUid)) {
            throw new AiIframeTitleSuggestionException('permission_denied', 'You do not have permission to request an AI suggestion for this finding.', 1771002923);
        }

        $iframeSrc = $this->extractSafeIframeSrc(trim((string)($issue['context_snippet'] ?? '')));
        if ($iframeSrc === '') {
            throw new AiIframeTitleSuggestionException('unsupported_context', 'AQG could not safely identify one exact iframe source in this rendered finding.', 1771002924);
        }

        $siteIdentifier = trim((string)($issue['site_identifier'] ?? ''));
        $languageUid = (int)($issue['source_lang_uid'] ?? 0);

        return new AiIframeTitleSuggestionContext(
            findingId: $findingId,
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            languageUid: $languageUid,
            ruleId: $ruleId,
            iframeSrc: $iframeSrc,
            contextPath: mb_substr(trim((string)($issue['context_path'] ?? '')), 0, 240),
            cssSelector: mb_substr(trim((string)($issue['css_selector'] ?? '')), 0, 240),
            frontendUrl: $this->sanitizeFrontendUrl(trim((string)($issue['frontend_url'] ?? ''))),
            targetLocale: $this->resolveTargetLocale($siteIdentifier, $languageUid),
            pageTitle: $this->resolvePageTitle($pageUid),
        );
    }

    private function extractSafeIframeSrc(string $snippet): string
    {
        if ($snippet === '' || stripos($snippet, '<iframe') === false) {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<!DOCTYPE html><html><body>' . $snippet . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return '';
        }

        $iframes = $document->getElementsByTagName('iframe');
        if ($iframes->length !== 1) {
            return '';
        }

        $iframe = $iframes->item(0);
        if (!$iframe instanceof \DOMElement) {
            return '';
        }

        return $this->sanitizeIframeSrc(trim($iframe->getAttribute('src')));
    }

    private function sanitizeIframeSrc(string $src): string
    {
        $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $src = trim((string)preg_replace('/\s+/u', ' ', $src));
        if ($src === '' || preg_match('/[\x00-\x1F\x7F<>]/u', $src) === 1) {
            return '';
        }

        if (preg_match('~^(?:javascript|data|about|blob):~i', $src) === 1) {
            return '';
        }

        $parts = parse_url($src);
        if ($parts === false) {
            return '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        unset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
        $safe = $this->buildUrl($parts, str_starts_with($src, '//'));

        return mb_substr($safe, 0, 500);
    }

    /** @param array<string,int|string> $parts */
    private function buildUrl(array $parts, bool $protocolRelative): string
    {
        $host = trim((string)($parts['host'] ?? ''));
        $scheme = trim((string)($parts['scheme'] ?? ''));
        $path = trim((string)($parts['path'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        if ($host !== '') {
            return ($protocolRelative ? '//' : ($scheme !== '' ? $scheme . '://' : '')) . $host . $port . $path;
        }

        return $path;
    }

    private function sanitizeFrontendUrl(string $url): string
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F<>]/u', $url) === 1) {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        unset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);

        return mb_substr($this->buildUrl($parts, str_starts_with($url, '//')), 0, 500);
    }

    private function resolveTargetLocale(string $siteIdentifier, int $languageUid): string
    {
        if ($siteIdentifier === '') {
            return 'en';
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            foreach ($site->getAllLanguages() as $language) {
                if ($language->getLanguageId() !== $languageUid) {
                    continue;
                }
                return $this->normalizeLocale($language->getLocale()->getName(), $language->getLocale()->getLanguageCode());
            }

            $defaultLocale = $site->getDefaultLanguage()->getLocale();
            return $this->normalizeLocale($defaultLocale->getName(), $defaultLocale->getLanguageCode());
        } catch (\Throwable) {
            return 'en';
        }
    }

    private function normalizeLocale(string $locale, string $languageCode): string
    {
        $locale = str_replace('_', '-', trim($locale));
        if ($locale !== '' && preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{2,8})*$/D', $locale) === 1) {
            return $locale;
        }

        $languageCode = strtolower(trim($languageCode));
        return preg_match('/^[a-z]{2,8}$/D', $languageCode) === 1 ? $languageCode : 'en';
    }

    private function resolvePageTitle(int $pageUid): string
    {
        if ($pageUid <= 0) {
            return '';
        }

        try {
            $page = BackendUtility::getRecord(Tables::PAGES, $pageUid, 'title');
        } catch (\Throwable) {
            return '';
        }

        return is_array($page) ? trim((string)($page['title'] ?? '')) : '';
    }
}
