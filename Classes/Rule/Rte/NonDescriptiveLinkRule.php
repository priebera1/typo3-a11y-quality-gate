<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class NonDescriptiveLinkRule extends AbstractRteRule
{
    /**
     * @var list<string>|null
     */
    private ?array $resolvedPhrases = null;

    /**
     * @param list<string> $defaultPhrases
     */
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly array $defaultPhrases,
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.non_descriptive_link';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Link text is not descriptive.';
    }

    public function getHint(): string
    {
        return 'Replace generic link text with text that describes the destination or action.';
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

            if (!$link->hasAttribute('href')) {
                continue;
            }

            $ariaLabel = $this->normalizedText($link->getAttribute('aria-label'));
            $visibleText = $this->normalizedText($link->textContent);

            if ($visibleText === '') {
                continue;
            }

            $textToCheck = $ariaLabel !== '' ? $ariaLabel : $visibleText;

            if (!$this->isNonDescriptive($textToCheck)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Link text "%s" is not descriptive.', $visibleText),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function getPhrases(): array
    {
        if ($this->resolvedPhrases !== null) {
            return $this->resolvedPhrases;
        }

        $adminPhrases = $this->loadAdminPhrases();

        $this->resolvedPhrases = array_values(array_unique(array_merge(
            $this->normalizePhrases($this->defaultPhrases),
            $adminPhrases,
        )));

        return $this->resolvedPhrases;
    }

    /**
     * @return list<string>
     */
    private function loadAdminPhrases(): array
    {
        try {
            $raw = (string)($this->extensionConfiguration->get(
                'a11y_quality_gate',
                'nonDescriptiveLinkPhrases'
            ) ?? '');
        } catch (\Throwable) {
            return [];
        }

        if (trim($raw) === '') {
            return [];
        }

        $normalizedRaw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/', $normalizedRaw) ?: [];

        $parts = array_map(
            static fn(mixed $value): string => trim((string)$value),
            $parts
        );

        $parts = array_values(array_filter(
            $parts,
            static fn(string $value): bool => $value !== ''
        ));

        $parts = array_slice($parts, 0, 100);
        $parts = array_values(array_filter(
            $parts,
            static fn(string $value): bool => mb_strlen($value) <= 100
        ));

        return $this->normalizePhrases($parts);
    }

    /**
     * @param array<int, string> $phrases
     * @return list<string>
     */
    private function normalizePhrases(array $phrases): array
    {
        $result = [];

        foreach ($phrases as $phrase) {
            $normalized = $this->normalize($phrase);

            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }

    private function isNonDescriptive(string $text): bool
    {
        return in_array($this->normalize($text), $this->getPhrases(), true);
    }

    private function normalize(string $text): string
    {
        $normalized = mb_strtolower($this->normalizedText($text));
        $normalized = (string)preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        $normalized = (string)preg_replace('/\s+/u', ' ', $normalized);

        return trim($normalized);
    }
}