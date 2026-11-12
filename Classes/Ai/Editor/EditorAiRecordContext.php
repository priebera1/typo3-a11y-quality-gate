<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

/**
 * The record and field an editor works in, resolved server-side (workspace overlay, page, site, language). The
 * editor sends only table, uid and field; nothing else of this is taken from the request.
 */
final readonly class EditorAiRecordContext
{
    public function __construct(
        public string $table,
        public int $uid,
        public string $field,
        public int $pageUid,
        public string $siteIdentifier,
        public int $languageUid,
        public string $targetLocale,
        public string $languageTitle,
        public string $defaultLocale,
        public int $workspaceId,
        public bool $isTranslation,
        public string $cType = '',
    ) {}
}
