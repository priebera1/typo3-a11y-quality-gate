<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;

final class IssueFilterUtility
{
    private function __construct()
    {
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    public static function filterByStatus(array $issues, string $status): array
    {
        return match ($status) {
            'ignored' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Ignored->value
            )),
            'resolved' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Resolved->value
            )),
            'all' => $issues,
            default => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Open->value
            )),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    public static function filterBySeverity(array $issues, string $severity): array
    {
        return match ($severity) {
            'critical' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Critical->value
            )),
            'warning' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Warning->value
            )),
            'info' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Info->value
            )),
            default => $issues,
        };
    }
}