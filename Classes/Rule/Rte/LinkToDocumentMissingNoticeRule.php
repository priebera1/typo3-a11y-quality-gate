<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class LinkToDocumentMissingNoticeRule extends AbstractRteRule
{
    public function __construct(
        private readonly LinkToDocumentRule $linkToDocumentRule,
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.link_to_document_missing_notice';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Info;
    }

    public function getMessage(): string
    {
        return 'Document link does not mention the file type.';
    }

    public function getHint(): string
    {
        return 'Add the file type to the link text or accessible name, for example "Annual report 2024 (PDF)".';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);

        foreach ($dom->getElementsByTagName('a') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $extension = $this->linkToDocumentRule->getDocumentExtension($link->getAttribute('href'));
            if ($extension === null) {
                continue;
            }

            if ($this->mentionsFileType($link, $extension, $xpath)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Document link points to a %s file but the link text does not mention the file type.', strtoupper($extension)),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }

    private function mentionsFileType(\DOMElement $link, string $extension, \DOMXPath $xpath): bool
    {
        $textToCheck = PhraseMatcher::normalize(implode(' ', array_filter([
            $link->textContent,
            $link->getAttribute('aria-label'),
            $this->resolveAriaLabelledByText($link, $xpath),
            $link->getAttribute('title'),
        ])));

        if ($textToCheck === '') {
            return false;
        }

        $extension = strtolower($extension);

        return preg_match('/(?<![a-z0-9])' . preg_quote($extension, '/') . '(?![a-z0-9])/i', $textToCheck) === 1;
    }
}
