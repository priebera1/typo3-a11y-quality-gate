<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class BackendTimeUtility
{
    public static function formatDateTime(int $timestamp, string $format = 'd.m.Y H:i'): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(self::resolveTimeZone())
            ->format($format);
    }

    private static function resolveTimeZone(): DateTimeZone
    {
        $backendUserTimeZone = self::readString($GLOBALS['BE_USER']->uc['timezone'] ?? null)
            ?? self::readString($GLOBALS['BE_USER']->uc['timeZone'] ?? null);

        foreach ([
            $backendUserTimeZone,
            self::readString($GLOBALS['TYPO3_CONF_VARS']['SYS']['phpTimeZone'] ?? null),
            date_default_timezone_get(),
        ] as $timeZoneName) {
            if ($timeZoneName === null || $timeZoneName === '') {
                continue;
            }

            try {
                return new DateTimeZone($timeZoneName);
            } catch (Throwable) {
                continue;
            }
        }

        return new DateTimeZone('UTC');
    }

    private static function readString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
