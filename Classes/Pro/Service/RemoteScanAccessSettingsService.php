<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Service\SecretEncryptionService;
use Priebera\A11yQualityGate\Utility\StringListUtility;

/**
 * The site's remote scan access settings — scanner preview token, HTTP authentication, excluded and
 * priority URLs, cookie banner selectors — with the default ruleset as fallback. Backend scans, fix
 * verification and scheduled monitoring send exactly the same settings for a site.
 */
final class RemoteScanAccessSettingsService
{
    public function __construct(
        private readonly RulesetRepository $rulesetRepository,
        private readonly SecretEncryptionService $secretEncryptionService,
    ) {
    }

    /**
     * @return array{scannerPreviewToken:string,scannerTokenLength:int,resolvedRulesetUid:int,resolvedRulesetSiteIdentifier:string,httpAuthUser:string,httpAuthPass:string,excludedPatterns:list<string>,priorityUrls:list<string>,cookieSelectors:list<string>}
     */
    public function buildForSite(string $siteIdentifier = ''): array
    {
        $defaultRuleset = $this->rulesetRepository->findDefault();
        $siteRuleset = $siteIdentifier !== ''
            ? $this->rulesetRepository->findBySiteIdentifier($siteIdentifier)
            : null;

        if (!is_array($defaultRuleset) && !is_array($siteRuleset)) {
            return [
                'scannerPreviewToken' => '',
                'scannerTokenLength' => 0,
                'resolvedRulesetUid' => 0,
                'resolvedRulesetSiteIdentifier' => '',
                'httpAuthUser' => '',
                'httpAuthPass' => '',
                'excludedPatterns' => [],
                'priorityUrls' => [],
                'cookieSelectors' => [],
            ];
        }

        $scannerToken = $this->firstNonEmptyRulesetValue($siteRuleset, $defaultRuleset, 'scanner_token');
        $scannerTokenRuleset = $this->resolveRulesetForFirstNonEmptyValue($siteRuleset, $defaultRuleset, 'scanner_token');
        $httpAuthUser = $this->firstNonEmptyRulesetValue($siteRuleset, $defaultRuleset, 'http_auth_user');
        $encryptedHttpAuthPass = $this->firstNonEmptyRulesetValue($siteRuleset, $defaultRuleset, 'http_auth_pass');
        $excludedPatterns = $this->firstNonEmptyRulesetList($siteRuleset, $defaultRuleset, 'excluded_patterns');
        $priorityUrls = $this->firstNonEmptyRulesetList($siteRuleset, $defaultRuleset, 'crawl_priority_urls');
        $cookieSelectors = $this->firstNonEmptyRulesetList($siteRuleset, $defaultRuleset, 'cookie_accept_selectors');

        $httpAuthPass = $encryptedHttpAuthPass;
        if ($httpAuthPass !== '') {
            $httpAuthPass = $this->secretEncryptionService->decrypt($httpAuthPass);
        }

        return [
            'scannerPreviewToken' => $scannerToken,
            'scannerTokenLength' => strlen($scannerToken),
            'resolvedRulesetUid' => is_array($scannerTokenRuleset) ? (int)($scannerTokenRuleset['uid'] ?? 0) : 0,
            'resolvedRulesetSiteIdentifier' => is_array($scannerTokenRuleset) ? (string)($scannerTokenRuleset['site_identifier'] ?? '') : '',
            'httpAuthUser' => $httpAuthUser,
            'httpAuthPass' => $httpAuthPass,
            'excludedPatterns' => $excludedPatterns,
            'priorityUrls' => $priorityUrls,
            'cookieSelectors' => $cookieSelectors,
        ];
    }

    /**
     * @param array<string, mixed>|null $siteRuleset
     * @param array<string, mixed>|null $defaultRuleset
     */
    private function firstNonEmptyRulesetValue(?array $siteRuleset, ?array $defaultRuleset, string $field): string
    {
        $siteValue = trim((string)($siteRuleset[$field] ?? ''));
        if ($siteValue !== '') {
            return $siteValue;
        }

        return trim((string)($defaultRuleset[$field] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $siteRuleset
     * @param array<string, mixed>|null $defaultRuleset
     * @return array<string, mixed>|null
     */
    private function resolveRulesetForFirstNonEmptyValue(?array $siteRuleset, ?array $defaultRuleset, string $field): ?array
    {
        $siteValue = trim((string)($siteRuleset[$field] ?? ''));
        if ($siteValue !== '') {
            return $siteRuleset;
        }

        $defaultValue = trim((string)($defaultRuleset[$field] ?? ''));
        if ($defaultValue !== '') {
            return $defaultRuleset;
        }

        return $siteRuleset ?? $defaultRuleset;
    }

    /**
     * @param array<string, mixed>|null $siteRuleset
     * @param array<string, mixed>|null $defaultRuleset
     * @return list<string>
     */
    private function firstNonEmptyRulesetList(?array $siteRuleset, ?array $defaultRuleset, string $field): array
    {
        $siteList = StringListUtility::decodeJsonList((string)($siteRuleset[$field] ?? '[]'));
        if ($siteList !== []) {
            return $siteList;
        }

        return StringListUtility::decodeJsonList((string)($defaultRuleset[$field] ?? '[]'));
    }
}
