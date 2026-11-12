<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

/**
 * One base64 image for a provider request. Built from the data URL AQG's image payload builder produces, so the
 * same bytes reach OpenAI (data URL) and Anthropic (base64 source block).
 */
final readonly class AiImageInput
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private function __construct(
        public string $mimeType,
        public string $base64Data,
    ) {}

    public static function fromDataUrl(string $dataUrl): self
    {
        if (preg_match('#^data:(image/[a-z]+);base64,([A-Za-z0-9+/]+={0,2})$#', $dataUrl, $matches) !== 1
            || !in_array($matches[1], self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('The AI image input must be a base64 JPEG, PNG or WebP data URL.');
        }

        return new self($matches[1], $matches[2]);
    }

    public function dataUrl(): string
    {
        return 'data:' . $this->mimeType . ';base64,' . $this->base64Data;
    }
}
