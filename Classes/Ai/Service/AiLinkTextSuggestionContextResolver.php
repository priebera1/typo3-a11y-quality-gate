<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionContext;
use Priebera\A11yQualityGate\Ai\Exception\AiLinkTextSuggestionException;
use Priebera\A11yQualityGate\Contract\BackendRecordAccessServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;

final class AiLinkTextSuggestionContextResolver
{
    public const SUPPORTED_RULE_IDS = [
        'rte.non_descriptive_link',
        'rte.empty_link',
        'rendered.empty_link',
    ];

    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly BackendRecordAccessServiceInterface $recordAccessService,
        private readonly SiteFinder $siteFinder,
        private readonly AiLinkTextSuggestionCandidateExtractor $candidateExtractor,
    ) {}

    public function resolve(int $findingId): AiLinkTextSuggestionContext
    {
        $issue = $this->issueRepository->findByUid($findingId);
        if (!is_array($issue)) {
            throw new AiLinkTextSuggestionException('finding_not_found', 'The requested finding could not be found.', 1771002820);
        }

        $ruleId = trim((string)($issue['rule_id'] ?? ''));
        if (!in_array($ruleId, self::SUPPORTED_RULE_IDS, true)) {
            throw new AiLinkTextSuggestionException('unsupported_rule', 'AI link-text suggestions are not available for this rule.', 1771002821);
        }

        if ($ruleId === 'rendered.empty_link') {
            return $this->resolveRenderedEmptyLink($findingId, $issue, $ruleId);
        }

        return $this->resolveRteLink($findingId, $issue, $ruleId);
    }

    /** @param array<string,mixed> $issue */
    private function resolveRteLink(int $findingId, array $issue, string $ruleId): AiLinkTextSuggestionContext
    {
        $sourceTable = trim((string)($issue['source_table'] ?? ''));
        $sourceUid = (int)($issue['source_uid'] ?? 0);
        $sourceField = trim((string)($issue['source_field'] ?? ''));
        $pageUid = (int)($issue['page_uid'] ?? 0);
        if ($sourceTable === '' || $sourceUid <= 0 || $sourceField === '' || $pageUid <= 0) {
            throw new AiLinkTextSuggestionException('unsupported_context', 'The source record context is incomplete.', 1771002822);
        }

        if (!$this->recordAccessService->isRecordOnPage($sourceTable, $sourceUid, $pageUid)
            || !$this->recordAccessService->canEditRecordFields($sourceTable, $sourceUid, [$sourceField])) {
            throw new AiLinkTextSuggestionException('permission_denied', 'You do not have permission to request an AI suggestion for this finding.', 1771002823);
        }

        $sourceRecord = BackendUtility::getRecord($sourceTable, $sourceUid, '*');
        if (!is_array($sourceRecord) || !array_key_exists($sourceField, $sourceRecord)) {
            throw new AiLinkTextSuggestionException('unsupported_context', 'The source field could not be loaded.', 1771002824);
        }

        $html = (string)($sourceRecord[$sourceField] ?? '');
        if (trim($html) === '') {
            throw new AiLinkTextSuggestionException('unsupported_context', 'The source field is empty.', 1771002825);
        }

        $candidate = $this->candidateExtractor->resolve($html, $issue);
        if ($candidate === null) {
            throw new AiLinkTextSuggestionException('unsupported_context', $this->unsupportedContextMessage($ruleId), 1771002826);
        }

        $siteIdentifier = trim((string)($issue['site_identifier'] ?? ''));
        $languageUid = $this->resolveLanguageUid($issue, $sourceRecord);

        return new AiLinkTextSuggestionContext(
            findingId: $findingId,
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            languageUid: $languageUid,
            ruleId: $ruleId,
            sourceTable: $sourceTable,
            sourceUid: $sourceUid,
            sourceField: $sourceField,
            currentLinkText: $candidate['text'],
            href: $candidate['href'],
            surroundingText: $candidate['surroundingText'],
            targetLocale: $this->resolveTargetLocale($siteIdentifier, $languageUid),
            pageTitle: $this->resolvePageTitle($pageUid),
        );
    }

    /** @param array<string,mixed> $issue */
    private function resolveRenderedEmptyLink(int $findingId, array $issue, string $ruleId): AiLinkTextSuggestionContext
    {
        $pageUid = (int)($issue['page_uid'] ?? 0);
        if ($pageUid <= 0) {
            throw new AiLinkTextSuggestionException('unsupported_context', 'The rendered link finding is not connected to a page.', 1771002827);
        }

        $sourceTable = trim((string)($issue['source_table'] ?? ''));
        $sourceUid = (int)($issue['source_uid'] ?? 0);
        if ($sourceTable === '' || $sourceUid <= 0) {
            $sourceTable = Tables::PAGES;
            $sourceUid = $pageUid;
        }

        if (!$this->recordAccessService->isRecordOnPage($sourceTable, $sourceUid, $pageUid)
            || !$this->recordAccessService->canEditRecord($sourceTable, $sourceUid)) {
            throw new AiLinkTextSuggestionException('permission_denied', 'You do not have permission to request an AI suggestion for this finding.', 1771002828);
        }

        $snippet = trim((string)($issue['context_snippet'] ?? ''));
        if ($snippet === '') {
            throw new AiLinkTextSuggestionException('unsupported_context', 'AQG could not safely identify one exact empty link in this rendered finding.', 1771002829);
        }

        $candidate = $this->candidateExtractor->resolve($snippet, $issue);
        if ($candidate === null) {
            throw new AiLinkTextSuggestionException('unsupported_context', 'AQG could not safely identify one exact empty link in this rendered finding.', 1771002830);
        }

        $siteIdentifier = trim((string)($issue['site_identifier'] ?? ''));
        $languageUid = (int)($issue['source_lang_uid'] ?? 0);
        $contextBits = array_filter([
            $candidate['surroundingText'],
            trim((string)($issue['context_path'] ?? '')) !== '' ? 'Location: ' . trim((string)($issue['context_path'] ?? '')) : '',
            trim((string)($issue['css_selector'] ?? '')) !== '' ? 'CSS selector: ' . trim((string)($issue['css_selector'] ?? '')) : '',
            trim((string)($issue['frontend_url'] ?? '')) !== '' ? 'Frontend URL: ' . $this->sanitizeUrl(trim((string)($issue['frontend_url'] ?? ''))) : '',
        ]);

        return new AiLinkTextSuggestionContext(
            findingId: $findingId,
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            languageUid: $languageUid,
            ruleId: $ruleId,
            sourceTable: $sourceTable,
            sourceUid: $sourceUid,
            sourceField: trim((string)($issue['source_field'] ?? '__rendered_html')),
            currentLinkText: '',
            href: $candidate['href'],
            surroundingText: mb_substr(implode(' ', $contextBits), 0, 500),
            targetLocale: $this->resolveTargetLocale($siteIdentifier, $languageUid),
            pageTitle: $this->resolvePageTitle($pageUid),
        );
    }

    /** @param array<string,mixed> $issue @param array<string,mixed> $sourceRecord */
    private function resolveLanguageUid(array $issue, array $sourceRecord): int
    {
        $recordLanguageUid = (int)($sourceRecord['sys_language_uid'] ?? -1);
        if ($recordLanguageUid >= 0) {
            return $recordLanguageUid;
        }

        return (int)($issue['source_lang_uid'] ?? 0);
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
            $page = BackendUtility::getRecord('pages', $pageUid, 'title');
        } catch (\Throwable) {
            return '';
        }

        return is_array($page) ? trim((string)($page['title'] ?? '')) : '';
    }

    private function sanitizeUrl(string $url): string
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

    private function unsupportedContextMessage(string $ruleId): string
    {
        return $ruleId === 'rte.empty_link'
            ? 'AQG could not safely identify one exact empty link in this source field.'
            : 'AQG could not safely identify one exact non-descriptive link in this source field.';
    }
}
