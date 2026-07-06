<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

final readonly class ImageFindingContext
{
    /** @param array<string,mixed> $issue @param array<string,mixed> $fileReference */
    public function __construct(
        public array $issue,
        public array $fileReference,
        public string $siteIdentifier,
        public int $pageUid,
        public int $languageUid,
        public string $sourceTable,
        public int $sourceUid,
        public string $sourceField,
        public int $fileReferenceUid,
        public int $fileUid,
        public string $fingerprint,
        public int $issueTimestamp,
        public int $fileReferenceTimestamp,
    ) {}

}
