<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Monitoring;

/**
 * The configured monitoring site or language no longer exists. The message is a translated, user-facing
 * explanation; it never contains exception text of TYPO3 or the scanner.
 */
final class InvalidMonitoringTargetException extends \RuntimeException
{
    public const SITE_MISSING = 1790000001;
    public const LANGUAGE_MISSING = 1790000002;
    public const SITE_REQUIRED = 1790000003;
}
