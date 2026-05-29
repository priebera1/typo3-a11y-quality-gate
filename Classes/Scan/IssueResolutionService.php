<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;

final class IssueResolutionService
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
    ) {
    }

    /**
     * @param array<int, string> $seenFingerprintsForPage
     * @param array{uid:int,name:string,username:string}|null $resolvedBy
     * @param array<int, string> $excludeSourceTypes
     */
    public function resolveUnseenForPage(
        int $pageUid,
        string $siteIdentifier,
        int $sourceLangUid,
        array $seenFingerprintsForPage,
        int $scanUid,
        ?array $resolvedBy,
        array $excludeSourceTypes = [],
    ): int {
        return $this->issueRepository->resolveUnseen(
            pageUid: $pageUid,
            siteIdentifier: $siteIdentifier,
            sourceLangUid: $sourceLangUid,
            seenFingerprints: array_values(array_unique($seenFingerprintsForPage)),
            scanUid: $scanUid,
            backendUserUid: (int)($resolvedBy['uid'] ?? 0),
            backendUserName: (string)($resolvedBy['name'] ?? ''),
            backendUsername: (string)($resolvedBy['username'] ?? ''),
            excludeSourceTypes: $excludeSourceTypes,
        );
    }

    public function resolveOpenRuleForPage(
        int $pageUid,
        string $siteIdentifier,
        int $sourceLangUid,
        string $ruleId,
        int $scanUid,
    ): int {
        return $this->issueRepository->resolveOpenRuleForPage(
            pageUid: $pageUid,
            siteIdentifier: $siteIdentifier,
            sourceLangUid: $sourceLangUid,
            ruleId: $ruleId,
            scanUid: $scanUid,
        );
    }

    public function resolveRenderedTechnicalErrorSnippetIssues(
        int $pageUid,
        string $siteIdentifier,
        int $sourceLangUid,
        int $scanUid,
    ): int {
        return $this->issueRepository->resolveRenderedTechnicalErrorSnippetIssues(
            pageUid: $pageUid,
            siteIdentifier: $siteIdentifier,
            sourceLangUid: $sourceLangUid,
            scanUid: $scanUid,
        );
    }


}
