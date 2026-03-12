<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class ImageInLinkMissingAltRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.image_in_link_missing_alt';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Linked image has no accessible text alternative.';
    }

    public function getHint(): string
    {
        return 'Provide meaningful alt text for the linked image or add an accessible name to the link.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom((string)$context->content);
        $xpath = $this->createXPath($dom);

        $links = $xpath->query('//a[@href]');

        if ($links === false) {
            return [];
        }

        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            $text = $this->normalizedText($link->textContent);
            $images = $xpath->query('./img', $link);

            if ($images === false || $images->length !== 1) {
                continue;
            }

            if ($text !== '') {
                continue;
            }

            if ($this->hasNonEmptyAttribute($link, 'aria-label') || $this->hasNonEmptyAttribute($link, 'title')) {
                continue;
            }

            $img = $images->item(0);
            if (!$img instanceof \DOMElement) {
                continue;
            }

            $alt = trim($img->getAttribute('alt'));
            if ($alt !== '') {
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
}
