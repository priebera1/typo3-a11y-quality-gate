<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;

final class LocalIssueGuidanceService
{
    public function __construct(
        private readonly RuleMetadataPresentationServiceInterface $ruleMetadataPresentationService,
    ) {}

    /** @return array<string, mixed> */
    public function present(array $issue, string $language = 'en'): array
    {
        $metadata = $this->ruleMetadataPresentationService->present($issue, $language);
        $hint = trim((string)($issue['hint'] ?? ''));
        $howToFix = trim((string)($metadata['howToFix'] ?? ''));
        $howToFixIsFallback = false;

        if ($howToFix === '' && $hint !== '') {
            $howToFix = $hint;
            $howToFixIsFallback = true;
        }

        $whyItMatters = trim((string)($metadata['whyItMatters'] ?? ''));
        $owner = trim((string)($metadata['owner'] ?? ''));
        $fixType = trim((string)($metadata['fixType'] ?? ''));
        $wcagPrimaryLabel = trim((string)($metadata['wcagPrimaryLabel'] ?? ''));
        $affectedUserItems = is_array($metadata['affectedUserItems'] ?? null)
            ? $metadata['affectedUserItems']
            : [];
        $wcagReferences = is_array($metadata['wcagReferences'] ?? null)
            ? $metadata['wcagReferences']
            : [];
        $techniques = is_array($metadata['techniques'] ?? null)
            ? $metadata['techniques']
            : [];
        $standards = is_array($metadata['standards'] ?? null)
            ? $metadata['standards']
            : [];
        $documentationLinks = is_array($metadata['documentationLinks'] ?? null)
            ? $metadata['documentationLinks']
            : [];
        $technicalTags = is_array($metadata['technicalTags'] ?? null)
            ? $metadata['technicalTags']
            : [];

        $hasBadges = $owner !== '' || $fixType !== '' || $wcagPrimaryLabel !== '';
        $hasGuidanceText = $whyItMatters !== '' || $howToFix !== '';
        $hasStandardsAndImpact = $wcagReferences !== []
            || $techniques !== []
            || $standards !== []
            || $documentationLinks !== []
            || $technicalTags !== [];

        return [
            'title' => trim((string)($metadata['title'] ?? '')),
            'whyItMatters' => $whyItMatters,
            'howToFix' => $howToFix,
            'howToFixIsFallback' => $howToFixIsFallback,
            'owner' => $owner,
            'ownerLabel' => trim((string)($metadata['ownerLabel'] ?? '')),
            'fixType' => $fixType,
            'fixTypeLabel' => trim((string)($metadata['fixTypeLabel'] ?? '')),
            'affectedUsers' => $metadata['affectedUsers'] ?? [],
            'affectedUserItems' => $affectedUserItems,
            'affectedUsersLabel' => trim((string)($metadata['affectedUsersLabel'] ?? '')),
            'wcagReferences' => $wcagReferences,
            'wcagPrimaryLabel' => $wcagPrimaryLabel,
            'wcagCompactLabel' => trim((string)($metadata['wcagCompactLabel'] ?? '')),
            'techniques' => $techniques,
            'standards' => $standards,
            'documentationLinks' => $documentationLinks,
            'technicalTags' => $technicalTags,
            'hasBadges' => $hasBadges,
            'hasGuidanceText' => $hasGuidanceText,
            'hasAffectedUsers' => $affectedUserItems !== [],
            'hasWcagReferences' => $wcagReferences !== [],
            'hasTechniques' => $techniques !== [],
            'hasStandards' => $standards !== [],
            'hasDocumentationLinks' => $documentationLinks !== [],
            'hasTechnicalTags' => $technicalTags !== [],
            'hasStandardsAndImpact' => $hasStandardsAndImpact,
            'hasAny' => $hasBadges || $hasGuidanceText || $affectedUserItems !== [] || $hasStandardsAndImpact,
        ];
    }
}
