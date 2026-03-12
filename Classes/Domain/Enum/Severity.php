<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Enum;

enum Severity: int
{
    case Critical = 1;
    case Warning = 2;
    case Info = 3;

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Warning',
            self::Info => 'Info',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Critical => 'badge-danger',
            self::Warning => 'badge-warning',
            self::Info => 'badge-info',
        };
    }

    public function iconIdentifier(): string
    {
        return match ($this) {
            self::Critical => 'status-dialog-error',
            self::Warning => 'status-dialog-warning',
            self::Info => 'status-dialog-information',
        };
    }

    public function isAtLeastAsSevereAs(self $threshold): bool
    {
        return $this->value <= $threshold->value;
    }

    public static function fromInt(int $value): self
    {
        return self::tryFrom($value) ?? self::Warning;
    }
}
