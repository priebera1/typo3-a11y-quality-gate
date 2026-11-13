<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwner;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;

final class RemediationStatusTest extends TestCase
{
    #[Test]
    public function onlyAnAqgCheckLeadsToVerified(): void
    {
        foreach (RemediationStatus::ALL as $from) {
            $allowed = RemediationStatus::canTransition($from, RemediationStatus::VERIFIED);
            self::assertSame(in_array($from, [RemediationStatus::SAVED, RemediationStatus::NOT_VERIFIED], true), $allowed, $from . ' must not jump to verified');
        }
    }

    #[Test]
    public function approvalIsNeitherASaveNorAVerification(): void
    {
        self::assertFalse(RemediationStatus::canTransition(RemediationStatus::PENDING_REVIEW, RemediationStatus::SAVED));
        self::assertFalse(RemediationStatus::canTransition(RemediationStatus::PENDING_REVIEW, RemediationStatus::VERIFIED));
        self::assertTrue(RemediationStatus::canTransition(RemediationStatus::APPROVED, RemediationStatus::SAVED), 'Only the stored record moves a rich-text approval to saved.');
        self::assertFalse(RemediationStatus::canTransition(RemediationStatus::IN_EDITOR, RemediationStatus::VERIFIED));
    }

    #[Test]
    public function savedStatesNeverGoBackToAProposalOrAConflict(): void
    {
        foreach (RemediationStatus::SAVED_STATES as $saved) {
            foreach ([RemediationStatus::PENDING_REVIEW, RemediationStatus::APPLYING, RemediationStatus::CONFLICT, RemediationStatus::REJECTED, RemediationStatus::FAILED] as $to) {
                self::assertFalse(RemediationStatus::canTransition($saved, $to), $saved . ' → ' . $to);
            }
        }
    }

    #[Test]
    public function terminalStatesAreFinalExceptReopeningARejection(): void
    {
        self::assertSame([], RemediationStatus::allowedFrom(RemediationStatus::VERIFIED));
        self::assertSame([], RemediationStatus::allowedFrom(RemediationStatus::NOT_APPLICABLE));
        self::assertTrue(RemediationStatus::canTransition(RemediationStatus::REJECTED, RemediationStatus::PENDING_REVIEW));
        self::assertTrue(RemediationStatus::isDone(RemediationStatus::REJECTED));
        self::assertFalse(RemediationStatus::isActive(RemediationStatus::REJECTED));
        self::assertTrue(RemediationStatus::isActive(RemediationStatus::NOT_VERIFIED), 'A saved but unverified change still holds its finding.');
    }

    #[Test]
    public function applyingEndsOnlyInSavedConflictOrFailed(): void
    {
        self::assertSame([RemediationStatus::SAVED, RemediationStatus::CONFLICT, RemediationStatus::FAILED], RemediationStatus::allowedFrom(RemediationStatus::APPLYING));
    }

    /** @return iterable<string,array{string,string,string,bool,string}> */
    public static function owners(): iterable
    {
        yield 'metadata editor' => ['editor', 'rte.non_descriptive_link', 'rte', true, RemediationOwner::EDITOR];
        yield 'metadata developer' => ['developer', 'rendered.button_name', 'rendered', false, RemediationOwner::DEVELOPER];
        yield 'metadata mixed' => ['mixed', 'rendered.x', 'rendered', false, RemediationOwner::MIXED];
        yield 'editor rule in template output' => ['editor', 'rendered.empty_link', 'rendered', false, RemediationOwner::MIXED];
        yield 'content rule without metadata' => ['', 'structured.file_reference_alt', 'structured', true, RemediationOwner::EDITOR];
        yield 'rendered without metadata or record' => ['', 'rendered.landmark', 'rendered', false, RemediationOwner::DEVELOPER];
        yield 'unknown stays unassigned' => ['', 'custom.something', 'custom', false, RemediationOwner::UNASSIGNED];
        yield 'fix type words are mapped, never guessed' => ['template', 'x', 'rte', false, RemediationOwner::DEVELOPER];
    }

    #[Test]
    #[DataProvider('owners')]
    public function ownerIsSuggestedFromRuleMetadataAndTarget(string $metadata, string $ruleId, string $sourceType, bool $editable, string $expected): void
    {
        self::assertSame($expected, RemediationOwner::suggest($metadata, $ruleId, $sourceType, $editable));
    }
}
