<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

final class DateTimeService
{
    public function toNullableTimestamp(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? $timestamp : null;
    }

    public function toTimestampOrZero(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? $timestamp : 0;
    }
}