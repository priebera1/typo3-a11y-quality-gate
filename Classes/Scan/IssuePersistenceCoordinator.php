<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class IssuePersistenceCoordinator
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
    ) {
    }

    /**
     * N-02 (review F-01): a rendered finding moved from the default record to its translation (RenderedTranslationRecords)
     * keeps its stored row — status, ignore decision and history — instead of becoming a new finding. Pairs are the
     * violations before and after the remap, in the same order.
     *
     * @param list<RuleViolation> $before
     * @param list<RuleViolation> $after
     */
    public function keepIdentityOfRemapped(array $before, array $after, CheckContext $context): int
    {
        $moved = 0;
        foreach ($before as $index => $violation) {
            $remapped = $after[$index] ?? null;
            if (!$remapped instanceof RuleViolation || $remapped->sourceUid === $violation->sourceUid) {
                continue;
            }
            $moved += $this->issueRepository->rekeyFinding(
                $context->siteIdentifier,
                $violation->fingerprint($context),
                $remapped->fingerprint($context),
                $remapped->sourceUid,
            );
        }

        return $moved;
    }

    /**
     * @param list<RuleViolation> $duplicates rendered findings of elements a rich-text rule already reported
     */
    public function retireRenderedDuplicates(array $duplicates, CheckContext $context): int
    {
        return $this->issueRepository->retireOpenDuplicates(
            $context->siteIdentifier,
            $context->pageUid,
            array_map(static fn (RuleViolation $violation): string => $violation->fingerprint($context), $duplicates),
        );
    }

    public function persistViolations(
        iterable $violations,
        CheckContext $context,
        int $scanUid,
        ScanResult $result,
        array &$seenFingerprintsForPage,
    ): void {
        foreach ($violations as $violation) {
            $fingerprint = $violation->fingerprint($context);
            $seenFingerprintsForPage[] = $fingerprint;

            $upsertResult = $this->issueRepository->upsert($violation, $context, $scanUid);

            match ($upsertResult) {
                'inserted' => $result->issuesNew++,
                'protected' => $result->issuesIgnored++,
                default => null,
            };
        }
    }
}
