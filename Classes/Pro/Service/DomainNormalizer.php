<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

/**
 * The domain of a site as the AQG service identifies it. FREE acceptances, the installation proof, licence domains and
 * domain grouping all compare this value with the AQG service's own, so it must be the same string: an internationalised
 * host (`müller.de`, which TYPO3 keeps in Unicode) is the ASCII form the service derives with WHATWG URL parsing
 * (UTS #46, non-transitional: `xn--mller-kva.de`). Otherwise the service records an acceptance for one string and the
 * extension looks it up under another, and the proof hash never matches.
 */
final class DomainNormalizer
{
    private const IDNA_OPTIONS = IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ;

    public function normalizeFromSiteBase(string $siteBase): string
    {
        $host = parse_url($siteBase, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return '';
        }

        return $this->normalizeHost($host);
    }

    public function normalizeHost(string $host): string
    {
        // WHATWG URL parsing percent-decodes a host before IDNA (`m%C3%BCller.de` is `müller.de`).
        if (str_contains($host, '%')) {
            $host = rawurldecode($host);
        }
        // The service lower-cases with JavaScript's Final_Sigma rule: Σ after a letter becomes ς unless a letter follows,
        // skipping case-ignorable characters such as `.` (so `ΟΔΟΣ.gr` keeps σ, `example.ΟΔΟΣ` gets ς). PHP 8.2's
        // mb_strtolower always gives σ, and σ and ς are different domains under non-transitional IDNA.
        $host = (string)preg_replace('/(?<=[\p{Lu}\p{Ll}\p{Lt}])Σ(?![.\p{Mn}\p{Me}\p{Cf}\p{Lm}\p{Sk}]*[\p{Lu}\p{Ll}\p{Lt}])/u', 'ς', $host);
        $normalizedHost = trim(mb_strtolower($host));

        if ($normalizedHost === '') {
            return '';
        }

        // ASCII hosts keep their established normalisation unchanged: ICU is stricter than the service for some ASCII
        // hosts (`ab--c.de`, long labels), and those must not stop working.
        if (preg_match('/[^\x00-\x7F]/', $normalizedHost) === 1) {
            $normalizedHost = $this->toAscii($normalizedHost);
            if ($normalizedHost === '') {
                return '';
            }
        }

        $normalizedHost = rtrim($normalizedHost, '.');

        if (str_starts_with($normalizedHost, 'www.')) {
            $normalizedHost = substr($normalizedHost, 4);
        }

        return $normalizedHost;
    }

    /**
     * The Unicode form of a normalised domain, for display only. Never compare or store it.
     */
    public function toDisplay(string $domain): string
    {
        if (!str_contains($domain, 'xn--')) {
            return $domain;
        }
        $unicode = idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);

        return is_string($unicode) && $unicode !== '' ? $unicode : $domain;
    }

    /**
     * Fail closed: a host that cannot be converted, or whose conversion is not a plain ASCII host name, has no domain
     * rather than one the service might read differently.
     */
    private function toAscii(string $host): string
    {
        $ascii = idn_to_ascii($host, self::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($ascii) || preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\.?$/', $ascii) !== 1) {
            return '';
        }

        return $ascii;
    }
}
