<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule;

final readonly class CheckContext
{
    public function __construct(
        public string $siteIdentifier,
        public int $pageUid,
        public int $sourceLangUid,
        public string $sourceTable,
        public int $sourceUid,
        public string $sourceField,
        public mixed $content,
        public string $cType = '',
        public string $contextPath = '',
    ) {
    }

    public function sourceLabel(): string
    {
        return sprintf(
            '%s:%d / field: %s',
            $this->sourceTable,
            $this->sourceUid,
            $this->sourceField
        );
    }
}
