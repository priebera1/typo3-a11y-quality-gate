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
    ) {
    }

    public function fingerprint(CheckContext $ctx): string
    {
        return sha1(implode('|', [
            $ctx->siteIdentifier,
            (string)$ctx->pageUid,
            (string)$ctx->sourceLangUid,
            $ctx->sourceTable . ':' . $ctx->sourceUid . ':' . $ctx->sourceField,
            $this->ruleId,
            $this->normalizeForFingerprint($this->contextSnippet, 100),
            $this->normalizeForFingerprint($this->contextPath, 100),
        ]));
    }

    private function normalizeForFingerprint(string $value, int $maxLength): string
    {
        $normalized = mb_strtolower($value);
        $normalized = (string)preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);

        return mb_substr($normalized, 0, $maxLength);
    }
}
