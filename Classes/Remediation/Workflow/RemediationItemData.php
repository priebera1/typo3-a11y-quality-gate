<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

/** Reading helpers for a tx_a11y_remediation_item row. */
final class RemediationItemData
{
    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function descriptor(array $item): array
    {
        $raw = (string)($item['target_descriptor'] ?? '');
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $descriptor */
    public static function encodeDescriptor(array $descriptor): string
    {
        return json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The value a person approves: their edit when they made one, otherwise the AI proposal.
     *
     * @param array<string,mixed> $item
     */
    public static function finalValue(array $item): string
    {
        $edited = (string)($item['edited_value'] ?? '');

        return $edited !== '' ? $edited : (string)($item['proposed_value'] ?? '');
    }

    /** @param array<string,mixed> $item */
    public static function isEdited(array $item): bool
    {
        return (string)($item['edited_value'] ?? '') !== '';
    }

    private function __construct()
    {
    }
}
