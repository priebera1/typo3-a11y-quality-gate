<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Enum;

enum IssueStatus: int
{
    case Open = 0;
    case Resolved = 1;
    case Ignored = 2;
    case Muted = 3;

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
            self::Ignored => 'Ignored',
            self::Muted => 'Muted',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'badge-open',
            self::Resolved => 'badge-success',
            self::Ignored => 'badge-secondary',
            self::Muted => 'badge-secondary',
        };
    }

    public function isProtected(): bool
    {
        return match ($this) {
            self::Ignored, self::Muted => true,
            default => false,
        };
    }

    public function countsForQualityGate(): bool
    {
        return $this === self::Open;
    }

    public static function fromInt(int $value): self
    {
        return self::tryFrom($value) ?? self::Open;
    }
}
