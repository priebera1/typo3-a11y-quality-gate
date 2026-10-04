<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\UpdateNotice;

/**
 * The latest stable AQG release as the AQG service reports it. Every field comes from the network and is
 * validated here: a version that is not a stable MAJOR.MINOR.PATCH rejects the whole answer, a link that is
 * not an https URL on an AQG or package registry host is replaced by AQG's own page.
 */
final class LatestRelease
{
    public const DEFAULT_RELEASE_NOTES_URL = 'https://typo3.priebera.sk/docs/changelog';
    public const DEFAULT_UPDATE_INSTRUCTIONS_URL = 'https://typo3.priebera.sk/docs/installation';

    private const ALLOWED_LINK_HOSTS = [
        'typo3.priebera.sk',
        'github.com',
        'extensions.typo3.org',
        'packagist.org',
        'docs.typo3.org',
    ];
    private const IMPORTANCE_VALUES = ['normal', 'recommended', 'security'];
    private const MAX_URL_LENGTH = 500;

    private function __construct(
        public readonly SemanticVersion $version,
        public readonly string $releasedAt,
        public readonly string $releaseNotesUrl,
        public readonly string $updateInstructionsUrl,
        public readonly string $importance,
    ) {
    }

    /**
     * Reads the `release` object of `GET /extension/releases/latest`, or a cached copy of it.
     */
    public static function fromArray(mixed $release): ?self
    {
        if (!is_array($release)) {
            return null;
        }

        $version = is_string($release['version'] ?? null) ? SemanticVersion::tryParse($release['version']) : null;
        if ($version === null) {
            return null;
        }

        $importance = is_string($release['importance'] ?? null) ? strtolower(trim($release['importance'])) : '';

        return new self(
            $version,
            self::sanitizeDate($release['releasedAt'] ?? null),
            self::sanitizeLink($release['releaseNotesUrl'] ?? null, self::DEFAULT_RELEASE_NOTES_URL),
            self::sanitizeLink($release['updateInstructionsUrl'] ?? null, self::DEFAULT_UPDATE_INSTRUCTIONS_URL),
            in_array($importance, self::IMPORTANCE_VALUES, true) ? $importance : 'normal',
        );
    }

    /**
     * @return array{version:string, releasedAt:string, releaseNotesUrl:string, updateInstructionsUrl:string, importance:string}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version->toString(),
            'releasedAt' => $this->releasedAt,
            'releaseNotesUrl' => $this->releaseNotesUrl,
            'updateInstructionsUrl' => $this->updateInstructionsUrl,
            'importance' => $this->importance,
        ];
    }

    public static function isAllowedLink(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f"<>\\\\`]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['port'])
            && in_array(strtolower((string)($parts['host'] ?? '')), self::ALLOWED_LINK_HOSTS, true);
    }

    private static function sanitizeLink(mixed $value, string $fallback): string
    {
        $url = is_string($value) ? trim($value) : '';

        return self::isAllowedLink($url) ? $url : $fallback;
    }

    private static function sanitizeDate(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
