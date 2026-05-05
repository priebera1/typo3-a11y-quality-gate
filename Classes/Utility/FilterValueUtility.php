<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

final class FilterValueUtility
{
    private const ALLOWED_STATUSES = ['open', 'ignored', 'resolved', 'all'];
    private const ALLOWED_SEVERITIES = ['all', 'critical', 'warning', 'info'];

    private function __construct()
    {
    }

    public static function normalizeStatus(string $status): string
    {
        return in_array($status, self::ALLOWED_STATUSES, true)
            ? $status
            : 'open';
    }

    public static function normalizeSeverity(string $severity): string
    {
        return in_array($severity, self::ALLOWED_SEVERITIES, true)
            ? $severity
            : 'all';
    }
}