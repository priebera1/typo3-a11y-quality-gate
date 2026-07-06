<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\FileReferenceRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;

final class ImageFindingContextResolver implements ImageFindingContextResolverInterface
{
    public const SUPPORTED_RULE_IDS = [
        'structured.file_reference_alt',
        'structured.file_reference_alt_quality',
    ];

    public function __construct(
        private readonly IssueRemediationRepositoryInterface $issueRepository,
        private readonly FileReferenceRepositoryInterface $fileReferenceRepository,
        private readonly SiteResolutionServiceInterface $siteResolutionService,
    ) {}

    public function supportsIssueRow(array $issue): bool
    {
        return in_array((string)($issue['rule_id'] ?? ''), self::SUPPORTED_RULE_IDS, true)
            && (string)($issue['source_type'] ?? '') === 'structured'
            && $this->extractFileReferenceUid((string)($issue['context_path'] ?? '')) > 0;
    }

    public function resolve(int $findingId): ImageFindingContext
    {
        $issue = $this->issueRepository->findByUid($findingId);
        if (!is_array($issue) || !$this->supportsIssueRow($issue)) {
            throw new ImageRemediationValidationException('unsupported_finding', 1771001001);
        }

        $fileReferenceUid = $this->extractFileReferenceUid((string)$issue['context_path']);
        $reference = $this->fileReferenceRepository->findByUid($fileReferenceUid);
        if (!is_array($reference) || (int)($reference['uid_local'] ?? 0) <= 0) {
            throw new StaleImageFindingException('reference_missing', 1771001002);
        }

        $resolvedSiteIdentifier = $this->siteResolutionService->resolveSiteIdentifierForPageId((int)$issue['page_uid'], '');
        if ($resolvedSiteIdentifier === '' || $resolvedSiteIdentifier !== (string)$issue['site_identifier']) {
            throw new StaleImageFindingException('site_mismatch', 1771001004);
        }

        $referenceLanguageUid = (int)($reference['sys_language_uid'] ?? 0);
        $issueLanguageUid = (int)($issue['source_lang_uid'] ?? 0);
        if ($referenceLanguageUid >= 0
            && $issueLanguageUid >= 0
            && $referenceLanguageUid !== $issueLanguageUid) {
            throw new StaleImageFindingException('language_mismatch', 1771001005);
        }

        if ((string)($reference['tablenames'] ?? '') !== (string)$issue['source_table']
            || (int)($reference['uid_foreign'] ?? 0) !== (int)$issue['source_uid']
            || (string)($reference['fieldname'] ?? '') !== (string)$issue['source_field']) {
            throw new StaleImageFindingException('reference_context_mismatch', 1771001003);
        }

        return new ImageFindingContext(
            issue: $issue,
            fileReference: $reference,
            siteIdentifier: (string)$issue['site_identifier'],
            pageUid: (int)$issue['page_uid'],
            languageUid: (int)$issue['source_lang_uid'],
            sourceTable: (string)$issue['source_table'],
            sourceUid: (int)$issue['source_uid'],
            sourceField: (string)$issue['source_field'],
            fileReferenceUid: $fileReferenceUid,
            fileUid: (int)$reference['uid_local'],
            fingerprint: (string)$issue['fingerprint'],
            issueTimestamp: (int)$issue['tstamp'],
            fileReferenceTimestamp: (int)($reference['tstamp'] ?? 0),
        );
    }

    private function extractFileReferenceUid(string $contextPath): int
    {
        return preg_match('/(?:^|\\s|>)ref:(\\d+)(?:\\s|$)/', $contextPath, $matches) === 1
            ? (int)$matches[1]
            : 0;
    }
}
