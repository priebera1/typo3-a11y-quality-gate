<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\FileMetadataAiService;
use Priebera\A11yQualityGate\Ai\Media\MediaAiPrompts;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Rule\RuleViolation;

/**
 * AF-03 (owner QA 2026-11-14): after a saved 133-character alt text, the quality rule (> 120 characters) reported the
 * same reference and the item read "still reports this issue". A check scoped to the finding's rule ignores it.
 */
final class ReferenceVerificationRuleScopeTest extends TestCase
{
    #[Test]
    public function aQualityRemarkOnTheSameReferenceIsNotTheMissingTextFinding(): void
    {
        $quality = new RuleViolation('structured.file_reference_alt_quality', Severity::Info, 'Alt text is long.', '', '', 'tt_content:1535 > image > ref:2006');

        self::assertTrue(FileMetadataAiService::reportsReference([$quality], 2006, 'tt_content', 1535, 'image'), 'Unscoped: any rule counts (old behaviour).');
        self::assertFalse(FileMetadataAiService::reportsReference([$quality], 2006, 'tt_content', 1535, 'image', ['structured.file_reference_alt']));
        $missing = new RuleViolation('structured.file_reference_alt', Severity::Critical, 'Missing alt.', '', '', 'tt_content:1535 > image > ref:2006');
        self::assertTrue(FileMetadataAiService::reportsReference([$quality, $missing], 2006, 'tt_content', 1535, 'image', ['structured.file_reference_alt']));
    }

    #[Test]
    public function theReferencePromptAsksForNoMoreThanTheQualityThreshold(): void
    {
        self::assertStringContainsString('at most ' . ImageAltTextValidator::QUALITY_WARNING_THRESHOLD . ' characters', MediaAiPrompts::referenceInstructions());
    }
}
