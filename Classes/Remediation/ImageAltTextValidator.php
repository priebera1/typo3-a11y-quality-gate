<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;

final class ImageAltTextValidator
{
    public const QUALITY_WARNING_THRESHOLD = 120;
    public const AI_PROMPT_TARGET_LENGTH = 125;

    public function __construct(private readonly FileReferenceSchemaServiceInterface $schemaService) {}

    public function validate(string $value): string
    {
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
        if ($value === '') {
            throw new \InvalidArgumentException('Alternative text must not be empty.', 1771001201);
        }
        if (mb_strlen($value) > $this->storageLimit()) {
            throw new \InvalidArgumentException('Alternative text is too long.', 1771001202);
        }
        return $value;
    }

    public function storageLimit(): int
    {
        return $this->schemaService->alternativeStorageLimit();
    }
}
