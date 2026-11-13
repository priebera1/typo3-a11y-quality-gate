<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

/**
 * Validates a decoded model answer against the flat JSON schemas AQG sends. The provider's structured-output mode is
 * not trusted on its own: every key must be declared, every declared key present, every value of its declared type
 * and, where given, one of its enum values.
 */
final class AiStructuredOutputContract
{
    public const MAX_STRING_LENGTH = 2000;

    /**
     * @param array<string,mixed> $schema
     * @return array<string,string|bool>
     * @throws \UnexpectedValueException when the answer does not match
     */
    public function validate(mixed $data, array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        $required = $schema['required'] ?? null;
        if (($schema['type'] ?? null) !== 'object'
            || ($schema['additionalProperties'] ?? null) !== false
            || !is_array($properties)
            || !is_array($required)) {
            throw new \UnexpectedValueException('AQG schema is not a closed flat object schema.');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new \UnexpectedValueException('The answer is not a JSON object.');
        }

        $expectedKeys = array_keys($properties);
        $actualKeys = array_keys($data);
        sort($expectedKeys);
        sort($actualKeys);
        $requiredKeys = $required;
        sort($requiredKeys);
        if ($actualKeys !== $expectedKeys || $requiredKeys !== $expectedKeys) {
            throw new \UnexpectedValueException('The answer keys do not match the schema.');
        }

        $validated = [];
        foreach ($properties as $key => $definition) {
            $value = $data[$key];
            $type = is_array($definition) ? ($definition['type'] ?? null) : null;
            $valid = match ($type) {
                'string' => is_string($value) && mb_strlen($value) <= self::MAX_STRING_LENGTH,
                'boolean' => is_bool($value),
                default => false,
            };
            if (!$valid) {
                throw new \UnexpectedValueException('An answer value has the wrong type.');
            }
            if (is_array($definition['enum'] ?? null) && !in_array($value, $definition['enum'], true)) {
                throw new \UnexpectedValueException('An answer value is not allowed by the schema.');
            }
            $validated[(string)$key] = $value;
        }

        return $validated;
    }
}
