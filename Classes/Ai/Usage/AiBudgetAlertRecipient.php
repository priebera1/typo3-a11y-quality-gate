<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettings;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Who receives the budget warning e-mail of a site (F-39), in this order:
 * 1. the address an administrator set for the site in Accessibility › Settings › AI suggestions › Limits ("site");
 * 2. otherwise TYPO3's own $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'], when it holds one valid address
 *    ("typo3") — the installation's configured address for backend warnings;
 * 3. otherwise nobody ("none"): no e-mail is sent and no alert is claimed.
 * The settings screen shows the effective recipient and its source. AQG never mails backend users or any other address.
 */
final readonly class AiBudgetAlertRecipient
{
    public const SOURCE_SITE = 'site';
    public const SOURCE_TYPO3 = 'typo3';
    public const SOURCE_NONE = 'none';

    public const MAX_LENGTH = 255;

    private function __construct(
        public string $address,
        public string $source,
    ) {}

    public static function resolve(AiSiteSettings $settings): self
    {
        $site = trim($settings->budgetAlertEmail);
        if ($site !== '' && self::isValid($site)) {
            return new self($site, self::SOURCE_SITE);
        }
        $typo3 = trim((string)($GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? ''));
        if ($typo3 !== '' && self::isValid($typo3)) {
            return new self($typo3, self::SOURCE_TYPO3);
        }

        return new self('', self::SOURCE_NONE);
    }

    public function exists(): bool
    {
        return $this->address !== '';
    }

    /**
     * Normalises the administrator's input: '' removes the address, anything else must be exactly one valid address.
     *
     * @throws \InvalidArgumentException invalid_alert_email
     */
    public static function normalizeInput(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (!self::isValid($input)) {
            throw new \InvalidArgumentException('invalid_alert_email');
        }

        return $input;
    }

    private static function isValid(string $address): bool
    {
        return strlen($address) <= self::MAX_LENGTH
            && preg_match('/[\s,;<>]/', $address) !== 1
            && GeneralUtility::validEmail($address);
    }
}
