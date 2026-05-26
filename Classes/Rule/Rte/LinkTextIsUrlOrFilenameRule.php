<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class LinkTextIsUrlOrFilenameRule extends AbstractRteRule
{
    /**
     * @param list<string> $documentExtensions
     */
    public function __construct(
        private readonly array $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'zip', 'txt'],
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.link_text_is_url_or_filename';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Link text is a raw URL or filename.';
    }

    public function getHint(): string
    {
        return 'Replace raw URLs or filenames with descriptive link text that explains the destination.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);

        foreach ($dom->getElementsByTagName('a') as $link) {
            if (!$link instanceof \DOMElement || !$link->hasAttribute('href')) {
                continue;
            }

            $text = $this->normalizedText($link->textContent);
            if ($text === '') {
                continue;
            }

            $detail = $this->classifyLinkText($text);
            if ($detail === null) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $detail === 'raw_url'
                    ? 'Link text is a raw URL.'
                    : 'Link text is only a filename.',
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }

    private function classifyLinkText(string $text): ?string
    {
        if (preg_match('~^(https?://|www\.)\S+$~i', $text) === 1) {
            return 'raw_url';
        }

        if (preg_match('/\s/u', $text) === 1 || str_contains($text, '/') || str_contains($text, '\\')) {
            return null;
        }

        $path = (string)(parse_url($text, PHP_URL_PATH) ?: $text);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return null;
        }

        $allowedExtensions = array_values(array_filter(array_map(
            static fn(string $value): string => strtolower(trim($value)),
            $this->documentExtensions,
        )));

        return in_array($extension, $allowedExtensions, true) ? 'filename' : null;
    }
}
