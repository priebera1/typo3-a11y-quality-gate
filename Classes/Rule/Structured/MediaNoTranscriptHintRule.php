<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class MediaNoTranscriptHintRule implements RuleInterface
{
    private const MEDIA_CTYPES = ['media'];
    private const MEDIA_FIELDS = ['media', 'assets'];
    private const EMBED_HOST_PATTERNS = [
        'youtube.com',
        'youtu.be',
        'youtube-nocookie.com',
        'vimeo.com',
        'player.vimeo.com',
    ];

    public function getRuleId(): string
    {
        return 'structured.media_no_transcript_hint';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::NeedsReview;
    }

    public function getMessage(): string
    {
        return 'Media content should be reviewed for captions or transcripts.';
    }

    public function getHint(): string
    {
        return 'This is a review item, not an automatic failure. Verify manually whether captions, transcripts or other required alternatives for time-based media are available.';
    }

    public function supports(CheckContext $context): bool
    {
        if ($context->sourceTable !== Tables::TT_CONTENT) {
            return false;
        }

        $cType = strtolower(trim($context->cType));
        $sourceField = strtolower(trim($context->sourceField));

        if ($cType !== 'uploads' && in_array($cType, self::MEDIA_CTYPES, true) && in_array($sourceField, self::MEDIA_FIELDS, true)) {
            return true;
        }

        return is_string($context->content)
            && trim($context->content) !== ''
            && in_array($sourceField, ['bodytext', 'subheader'], true);
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $cType = strtolower(trim($context->cType));
        $sourceField = strtolower(trim($context->sourceField));

        if ($cType !== 'uploads' && in_array($cType, self::MEDIA_CTYPES, true) && in_array($sourceField, self::MEDIA_FIELDS, true)) {
            return [
                new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: $this->getDefaultSeverity(),
                    message: $this->getMessage(),
                    hint: $this->getHint(),
                    contextSnippet: sprintf('CType=%s, field=%s', $cType, $sourceField),
                    contextPath: $context->contextPath,
                ),
            ];
        }

        if (!is_string($context->content) || !$this->containsKnownMediaEmbed($context->content)) {
            return [];
        }

        return [
            new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: 'Embedded video or audio content should be reviewed for captions or transcripts.',
                hint: $this->getHint(),
                contextSnippet: mb_substr(strip_tags($context->content), 0, 200),
                contextPath: $context->contextPath,
            ),
        ];
    }

    private function containsKnownMediaEmbed(string $html): bool
    {
        if (stripos($html, '<iframe') === false && stripos($html, '<video') === false && stripos($html, '<audio') === false) {
            return false;
        }

        if (stripos($html, '<video') !== false || stripos($html, '<audio') !== false) {
            return true;
        }

        foreach (self::EMBED_HOST_PATTERNS as $pattern) {
            if (stripos($html, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
