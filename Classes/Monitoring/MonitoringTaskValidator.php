<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Monitoring;

use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Checks a monitoring Scheduler task configuration before it is stored.
 *
 * "Complete" checks a final submit (TYPO3 13): a site, one of its languages and at least one recipient are
 * required. The TYPO3 14 form also saves when the site is switched (a FormEngine reload), before the user
 * could pick that site's language or type recipients; there only what was entered is checked, and FormEngine
 * enforces the required fields on the final save. A run resolves the target again in either case.
 */
final class MonitoringTaskValidator
{
    public const MAX_PAGES_MIN = 1;
    public const MAX_PAGES_MAX = 1000;
    public const MAX_PAGES_DEFAULT = 500;
    public const MAX_WAIT_MAX = 3600;
    public const MAX_WAIT_DEFAULT = 1200;

    public function __construct(
        private readonly MonitoringTargetResolver $monitoringTargetResolver,
    ) {}

    /**
     * @return list<string> translated messages, empty when the configuration can be stored
     */
    public function validate(
        string $siteIdentifier,
        int $languageUid,
        string $recipients,
        int $maxPages,
        int $maxWait,
        string $backendUrl,
        bool $complete,
    ): array {
        $errors = [];
        $siteIdentifier = trim($siteIdentifier);

        if ($siteIdentifier === '') {
            if ($complete) {
                $errors[] = BackendLabelUtility::translate('scheduler.monitoring.validation.siteRequired', 'Choose the site to monitor.');
            }
        } else {
            $site = $this->monitoringTargetResolver->findSite($siteIdentifier);
            if (!$site instanceof Site) {
                $errors[] = sprintf(
                    BackendLabelUtility::translate('scheduler.monitoring.validation.siteMissing', 'The site "%s" is not configured in this TYPO3 installation. Choose a configured site.'),
                    $siteIdentifier
                );
            } elseif ($complete && !$this->monitoringTargetResolver->isLanguageOfSite($site, $languageUid)) {
                $errors[] = sprintf(
                    BackendLabelUtility::translate('scheduler.monitoring.validation.languageMissing', 'Language %1$d is not an enabled language of the site "%2$s". Choose one of the site\'s languages.'),
                    $languageUid,
                    $siteIdentifier
                );
            }
        }

        $addresses = self::parseRecipients($recipients);
        if ($addresses === []) {
            if ($complete) {
                $errors[] = BackendLabelUtility::translate('scheduler.monitoring.validation.recipientsRequired', 'Enter at least one e-mail address that receives the notifications.');
            }
        } else {
            foreach ($addresses as $address) {
                if (!GeneralUtility::validEmail($address)) {
                    $errors[] = sprintf(
                        BackendLabelUtility::translate('scheduler.monitoring.validation.recipientInvalid', '"%s" is not a valid e-mail address.'),
                        $address
                    );
                }
            }
        }

        if ($maxPages < self::MAX_PAGES_MIN || $maxPages > self::MAX_PAGES_MAX) {
            $errors[] = sprintf(
                BackendLabelUtility::translate('scheduler.monitoring.validation.maxPages', 'Maximum pages per scan must be between %1$d and %2$d.'),
                self::MAX_PAGES_MIN,
                self::MAX_PAGES_MAX
            );
        }

        if ($maxWait < 0 || $maxWait > self::MAX_WAIT_MAX) {
            $errors[] = sprintf(
                BackendLabelUtility::translate('scheduler.monitoring.validation.maxWait', 'The waiting time must be between 0 and %d seconds.'),
                self::MAX_WAIT_MAX
            );
        }

        if (trim($backendUrl) !== '' && !self::isBackendBaseUrl($backendUrl)) {
            $errors[] = BackendLabelUtility::translate('scheduler.monitoring.validation.backendUrl', 'Enter the backend address as https://host (for example https://cms.example.org), or leave it empty.');
        }

        return $errors;
    }

    /**
     * Comma-, semicolon- or line-separated addresses, trimmed and de-duplicated.
     *
     * @return list<string>
     */
    public static function parseRecipients(string $recipients): array
    {
        $parts = preg_split('/[\s,;]+/', $recipients) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== '')));
    }

    /**
     * Scheme and host (optionally a port) of the backend: the notification appends the module path itself.
     */
    public static function isBackendBaseUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        foreach (['query', 'fragment', 'user', 'pass'] as $part) {
            if (isset($parts[$part])) {
                return false;
            }
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && ($parts['path'] ?? '/') === '/';
    }
}
