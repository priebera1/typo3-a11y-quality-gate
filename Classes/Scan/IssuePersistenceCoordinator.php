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
     * @param iterable<RuleViolation> $violations
     * @param array<int, string> $seenFingerprintsForPage
     */
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
