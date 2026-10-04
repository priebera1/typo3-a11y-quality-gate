<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\UpdateNotice;

/**
 * A stable MAJOR.MINOR.PATCH release version. Parts compare as numbers, so 1.9.10 is newer than 1.9.9.
 * Pre-release, build metadata, a "v" prefix and branch names such as dev-main do not parse: such an
 * installed or advertised version never produces an update notice.
 */
final class SemanticVersion
{
    private const PATTERN = '/^(0|[1-9]\d{0,3})\.(0|[1-9]\d{0,3})\.(0|[1-9]\d{0,3})$/';

    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
    ) {
    }

    public static function tryParse(string $value): ?self
    {
        if (preg_match(self::PATTERN, trim($value), $parts) !== 1) {
            return null;
        }

        return new self((int)$parts[1], (int)$parts[2], (int)$parts[3]);
    }

    public function isNewerThan(self $other): bool
    {
        return [$this->major, $this->minor, $this->patch] > [$other->major, $other->minor, $other->patch];
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor . '.' . $this->patch;
    }
}
