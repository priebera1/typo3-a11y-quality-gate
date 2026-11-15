<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiPrompts;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetKind;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;

/**
 * AF-05 (owner QA 2026-11-14): an "empty heading" suggestion from the first fix prompt proposed "Welcome to our corporate
 * website" for a heading without content. Such unedited suggestions are not approvable or reopened as they are.
 */
final class OutdatedSuggestionTest extends TestCase
{
    #[Test]
    public function anUneditedRichTextSuggestionOfTheFirstPromptIsOutdated(): void
    {
        self::assertTrue(RemediationWorkflowService::isOutdatedSuggestion([
            'target_kind' => RemediationTargetKind::RICH_TEXT, 'prompt_version' => 'aqg_editor_fix_v1', 'edited_value' => '',
        ]));
    }

    #[Test]
    public function aHumanEditTheCurrentPromptAndImagesAreNotOutdated(): void
    {
        self::assertFalse(RemediationWorkflowService::isOutdatedSuggestion([
            'target_kind' => RemediationTargetKind::RICH_TEXT, 'prompt_version' => 'aqg_editor_fix_v1', 'edited_value' => 'Our opening hours',
        ]));
        self::assertFalse(RemediationWorkflowService::isOutdatedSuggestion([
            'target_kind' => RemediationTargetKind::RICH_TEXT, 'prompt_version' => EditorAiPrompts::FIX_PROMPT_VERSION, 'edited_value' => '',
        ]));
        self::assertFalse(RemediationWorkflowService::isOutdatedSuggestion([
            'target_kind' => RemediationTargetKind::IMAGE_REFERENCE_ALT, 'prompt_version' => 'aqg_editor_fix_v1', 'edited_value' => '',
        ]));
        self::assertNotContains(EditorAiPrompts::FIX_PROMPT_VERSION, RemediationWorkflowService::OUTDATED_PROMPT_VERSIONS);
    }

    #[Test]
    public function anUneditedLinkTextOfTheSecondPromptIsOutdatedOtherKindsAreNot(): void
    {
        // v2 offered the title of the page the link is on as context: a link text could name that page instead of
        // the destination (observed: an empty link to /r1).
        $link = ['target_kind' => RemediationTargetKind::RICH_TEXT, 'prompt_version' => 'aqg_editor_fix_v2', 'edited_value' => '', 'target_descriptor' => '{"aiKind":"link_text"}'];
        self::assertTrue(RemediationWorkflowService::isOutdatedSuggestion($link));
        self::assertFalse(RemediationWorkflowService::isOutdatedSuggestion(['edited_value' => 'Contact and directions'] + $link));
        self::assertFalse(RemediationWorkflowService::isOutdatedSuggestion(['target_descriptor' => '{"aiKind":"iframe_title"}'] + $link));
        self::assertSame('aqg_editor_fix_v4', EditorAiPrompts::FIX_PROMPT_VERSION);
    }
}
