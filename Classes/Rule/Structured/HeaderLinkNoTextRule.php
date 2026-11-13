<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Workspace\PublishPreviewContext;
use TYPO3\CMS\Backend\Utility\BackendUtility;

final class HeaderLinkNoTextRule implements RuleInterface
{
    public function __construct(
        private readonly ?PublishPreviewContext $publishPreview = null,
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.header_link_no_text';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Header link is set, but the headline text is empty.';
    }

    public function getHint(): string
    {
        return 'Add visible headline text or remove the header link.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && $context->sourceField === 'header_link';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $linkValue = trim((string)$context->content);
        if ($linkValue === '') {
            return [];
        }

        // Before a workspace publish, the header the publish will store counts (PublishPreviewContext).
        $record = $this->publishPreview?->active()?->publishedVersionOf(Tables::TT_CONTENT, $context->sourceUid)
            ?? BackendUtility::getRecord(Tables::TT_CONTENT, $context->sourceUid, 'header');
        $header = is_array($record) ? trim((string)($record['header'] ?? '')) : '';

        if ($header !== '') {
            return [];
        }

        return [
            new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $linkValue,
                contextPath: $context->contextPath,
            ),
        ];
    }
}
