<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class LinkNewWindowNoWarningRule extends AbstractRteRule
{
    /**
     * @var list<string>|null
     */
    private ?array $resolvedHintPhrases = null;

    /**
     * @param list<string> $defaultHintPhrases
     */
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly array $defaultHintPhrases,
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.link_new_window_no_warning';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Link opens in a new window without warning the user.';
    }

    public function getHint(): string
    {
        return 'Add a visible or accessible hint such as "opens in new window" so users know what to expect.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $links = $xpath->query('//a[@target="_blank"]');

        if ($links === false) {
            return [];
        }

        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            if ($this->hasWindowWarning($link, $xpath)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }

    private function hasWindowWarning(\DOMElement $link, \DOMXPath $xpath): bool
    {
        if ($this->containsHint($link->getAttribute('aria-label'))) {
            return true;
        }

        if ($this->containsHint($link->getAttribute('title'))) {
            return true;
        }

        if ($this->containsHint($link->textContent)) {
            return true;
        }

        $childrenWithAriaLabel = $xpath->query('.//*[@aria-label]', $link);
        if ($childrenWithAriaLabel !== false) {
            foreach ($childrenWithAriaLabel as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }

                if ($this->containsHint($child->getAttribute('aria-label'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getHintPhrases(): array
    {
        if ($this->resolvedHintPhrases !== null) {
            return $this->resolvedHintPhrases;
        }

        $adminPhrases = $this->loadAdminHintPhrases();

        $this->resolvedHintPhrases = array_values(array_unique(array_merge(
            $this->normalizePhrases($this->defaultHintPhrases),
            $adminPhrases,
        )));

        return $this->resolvedHintPhrases;
    }

    /**
     * @return list<string>
     */
    private function loadAdminHintPhrases(): array
    {
        try {
            $raw = (string)($this->extensionConfiguration->get(
                'a11y_quality_gate',
                'linkNewWindowHintPhrases'
            ) ?? '');
        } catch (\Throwable) {
            return [];
        }

        if (trim($raw) === '') {
            return [];
        }

        $normalizedRaw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/', $normalizedRaw) ?: [];

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

    private function containsHint(string $text): bool
    {
        $normalizedText = $this->normalize($text);
        if ($normalizedText === '') {
            return false;
        }

        foreach ($this->getHintPhrases() as $phrase) {
            if ($phrase !== '' && str_contains($normalizedText, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $normalized = mb_strtolower($this->normalizedText($text));
        $normalized = (string)preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        $normalized = (string)preg_replace('/\s+/u', ' ', $normalized);

        return trim($normalized);
    }
}