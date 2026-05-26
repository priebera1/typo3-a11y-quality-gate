<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\DictionaryRegistry;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class ImgAltRedundantPhraseRule extends AbstractRteRule
{
    public function __construct(
        private readonly DictionaryRegistry $dictionaryRegistry,
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.img_alt_redundant_phrase';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Image alternative text starts with a redundant phrase.';
    }

    public function getHint(): string
    {
        return 'Remove redundant phrases such as "photo of" or "image of" from the alt text.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $phrases = $this->dictionaryRegistry->resolveForContext($this->getRuleId(), $context);
        $dom = $this->loadDom($context->content);

        foreach ($dom->getElementsByTagName('img') as $image) {
            if (!$image instanceof \DOMElement) {
                continue;
            }

            if (!$image->hasAttribute('alt')) {
                continue;
            }

            $alt = trim($image->getAttribute('alt'));
            if ($alt === '') {
                continue;
            }

            $normalizedAlt = PhraseMatcher::normalize($alt);
            $detail = $this->resolveStructuralRedundancyDetail($image, $normalizedAlt)
                ?? $this->resolvePhraseRedundancyDetail($normalizedAlt, $phrases);

            if ($detail === null) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->messageForDetail($detail),
                hint: $this->hintForDetail($detail),
                contextSnippet: $this->elementSnippet($image),
                contextPath: $this->buildXPath($image),
            );
        }

        return $violations;
    }

    /**
     * @param list<string> $phrases
     */
    private function resolvePhraseRedundancyDetail(string $normalizedAlt, array $phrases): ?string
    {
        if ($phrases === []) {
            return null;
        }

        return PhraseMatcher::isPrefixMatch($normalizedAlt, $phrases)
            ? 'redundant_prefix_phrase'
            : null;
    }

    private function resolveStructuralRedundancyDetail(\DOMElement $image, string $normalizedAlt): ?string
    {
        $figure = $this->closestAncestor($image, 'figure');
        if ($figure instanceof \DOMElement) {
            $captions = $figure->getElementsByTagName('figcaption');
            if ($captions->length > 0) {
                $captionText = PhraseMatcher::normalize($captions->item(0)?->textContent ?? '');
                if ($captionText !== '' && $captionText === $normalizedAlt) {
                    return 'redundant_figcaption';
                }
            }
        }

        $link = $this->closestAncestor($image, 'a');
        if ($link instanceof \DOMElement) {
            $linkText = PhraseMatcher::normalize($this->textContentWithoutImages($link));
            if ($linkText !== '' && $linkText === $normalizedAlt) {
                return 'redundant_link_text';
            }
        }

        return null;
    }

    private function closestAncestor(\DOMElement $element, string $tagName): ?\DOMElement
    {
        $tagName = strtolower($tagName);
        $parent = $element->parentNode;

        while ($parent !== null) {
            if ($parent instanceof \DOMElement && strtolower($parent->tagName) === $tagName) {
                return $parent;
            }

            $parent = $parent->parentNode;
        }

        return null;
    }

    private function textContentWithoutImages(\DOMElement $element): string
    {
        $text = '';

        foreach ($element->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $text .= $node->textContent;
                continue;
            }

            if ($node instanceof \DOMElement && strtolower($node->tagName) !== 'img') {
                $text .= $this->textContentWithoutImages($node);
            }
        }

        return $text;
    }

    private function messageForDetail(string $detail): string
    {
        return match ($detail) {
            'redundant_figcaption' => 'Image alt text repeats the figcaption text.',
            'redundant_link_text' => 'Image alt text repeats the surrounding link text.',
            default => $this->getMessage(),
        };
    }

    private function hintForDetail(string $detail): string
    {
        return match ($detail) {
            'redundant_figcaption' => 'Do not repeat the same information in both alt text and figcaption. Keep the alt text shorter or leave the caption to provide the visible description.',
            'redundant_link_text' => 'For linked images, avoid repeating the same label twice. The combined link purpose should be clear once.',
            default => $this->getHint(),
        };
    }
}
