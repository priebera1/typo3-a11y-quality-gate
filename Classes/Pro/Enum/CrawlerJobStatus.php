<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Enum;

enum CrawlerJobStatus: string
{
    case Waiting = 'waiting';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Queued = 'queued';
    case Unknown = 'unknown';

    public static function fromString(string $value): self
    {
        return self::tryFrom(trim(strtolower($value))) ?? self::Unknown;
    }

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}