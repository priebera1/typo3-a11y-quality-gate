<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;

final readonly class RuleViolation
{
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $message,
        public string $hint,
        public string $contextSnippet = '',
        public string $contextPath = '',
        public string $sourceType = '',
        public string $frontendUrl = '',
        public string $cssSelector = '',
        public string $sourceTable = '',
        public int $sourceUid = 0,
        public string $sourceField = '',
    ) {
    }

    public function fingerprint(CheckContext $ctx): string
    {
        $sourceType = $this->sourceType !== '' ? $this->sourceType : $ctx->sourceType;
        $sourceTable = $this->sourceTable !== '' ? $this->sourceTable : $ctx->sourceTable;
        $sourceUid = $this->sourceUid > 0 ? $this->sourceUid : $ctx->sourceUid;
        $sourceField = $this->sourceField !== '' ? $this->sourceField : $ctx->sourceField;

        $parts = [
            $ctx->siteIdentifier,
            (string)$ctx->pageUid,
            (string)$ctx->sourceLangUid,
            $sourceTable . ':' . $sourceUid . ':' . $sourceField,
            $this->ruleId,
            $this->normalizeForFingerprint($this->contextSnippet, 100),
            $this->normalizeForFingerprint($this->contextPath, 100),
        ];

        if ($sourceType === 'rendered') {
            $parts[] = 'rendered';
            $parts[] = $this->normalizeForFingerprint($this->cssSelector, 140);
        }

        return sha1(implode('|', $parts));
    }

    private function normalizeForFingerprint(string $value, int $maxLength): string
    {
        $normalized = mb_strtolower($value);
        $normalized = (string)preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);

        return mb_substr($normalized, 0, $maxLength);
    }
}
