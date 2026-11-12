<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

/**
 * The states of one remediation item and the only transitions between them.
 *
 * The four honest outcomes stay apart (Atlas D2): a suggestion (pending_review), an approval that still waits for the
 * editor (approved, in_editor), a TYPO3 save (saved) and an AQG check of the stored data (verified / not_verified).
 * Nothing becomes "verified" because it was approved, inserted or saved. Side exits keep their reason: a conflict (the
 * target changed after the suggestion), a failure (nothing written), a rejection, or a finding that no longer applies.
 */
final class RemediationStatus
{
    public const PREPARING = 'preparing';
    public const PENDING_REVIEW = 'pending_review';
    public const NO_SUGGESTION = 'no_suggestion';
    public const APPROVED = 'approved';
    public const IN_EDITOR = 'in_editor';
    public const APPLYING = 'applying';
    public const SAVED = 'saved';
    public const VERIFIED = 'verified';
    public const NOT_VERIFIED = 'not_verified';
    public const CONFLICT = 'conflict';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const NOT_APPLICABLE = 'not_applicable';

    public const ALL = [
        self::PREPARING, self::PENDING_REVIEW, self::NO_SUGGESTION, self::APPROVED, self::IN_EDITOR, self::APPLYING,
        self::SAVED, self::VERIFIED, self::NOT_VERIFIED, self::CONFLICT, self::FAILED, self::REJECTED, self::NOT_APPLICABLE,
    ];

    /** "Done" in the queue: nothing is left to decide or to check. */
    public const DONE = [self::VERIFIED, self::REJECTED, self::NOT_APPLICABLE];

    /** Items that hold a finding: a second item for the same finding would compete for the same target. */
    public const ACTIVE = [
        self::PREPARING, self::PENDING_REVIEW, self::NO_SUGGESTION, self::APPROVED, self::IN_EDITOR, self::APPLYING,
        self::SAVED, self::NOT_VERIFIED, self::CONFLICT, self::FAILED,
    ];

    /** States in which a stored change exists (Saved is never shown as Verified). */
    public const SAVED_STATES = [self::SAVED, self::VERIFIED, self::NOT_VERIFIED];

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::PREPARING => [self::PENDING_REVIEW, self::NO_SUGGESTION, self::FAILED, self::NOT_APPLICABLE, self::REJECTED],
        self::PENDING_REVIEW => [self::APPLYING, self::APPROVED, self::REJECTED, self::CONFLICT, self::NOT_APPLICABLE, self::PREPARING],
        self::NO_SUGGESTION => [self::PREPARING, self::REJECTED, self::NOT_APPLICABLE],
        self::APPROVED => [self::IN_EDITOR, self::SAVED, self::CONFLICT, self::REJECTED, self::NOT_APPLICABLE, self::PENDING_REVIEW],
        self::IN_EDITOR => [self::SAVED, self::APPROVED, self::CONFLICT, self::REJECTED, self::NOT_APPLICABLE],
        self::APPLYING => [self::SAVED, self::CONFLICT, self::FAILED],
        self::SAVED => [self::VERIFIED, self::NOT_VERIFIED],
        // A saved change that the check still reports leaves the queue when the finding closes (a later scan, an
        // ignore) — never by a click that would call it fixed.
        self::NOT_VERIFIED => [self::VERIFIED, self::NOT_VERIFIED, self::NOT_APPLICABLE],
        self::CONFLICT => [self::PREPARING, self::REJECTED, self::NOT_APPLICABLE],
        self::FAILED => [self::PREPARING, self::APPLYING, self::REJECTED, self::NOT_APPLICABLE, self::CONFLICT],
        self::REJECTED => [self::PENDING_REVIEW, self::NO_SUGGESTION],
        self::VERIFIED => [],
        self::NOT_APPLICABLE => [],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isDone(string $status): bool
    {
        return in_array($status, self::DONE, true);
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    private function __construct()
    {
    }
}
