<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class LinkToDocumentRule extends AbstractRteRule
{
    /**
     * @param list<string> $documentExtensions
     */
    public function __construct(
        private readonly array $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv'],
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.link_to_document';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::NeedsReview;
    }

    public function getMessage(): string
    {
        return 'Link points to a document file and should be reviewed for accessibility.';
    }

    public function getHint(): string
    {
        return 'Verify that the linked document itself is accessible and that the link text clearly identifies the document.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);

        foreach ($dom->getElementsByTagName('a') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $extension = $this->getDocumentExtension($link->getAttribute('href'));
            if ($extension === null) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Link points to a %s document. Verify that the file is accessible.', strtoupper($extension)),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }

    public function getDocumentExtension(string $href): ?string
    {
        $path = (string)(parse_url($href, PHP_URL_PATH) ?: '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        $allowed = array_map('strtolower', $this->documentExtensions);

        return in_array($extension, $allowed, true) ? $extension : null;
    }
}
