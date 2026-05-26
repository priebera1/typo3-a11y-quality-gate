<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class ImgAltMissingRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.img_alt_missing';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Image is missing an alt text attribute.';
    }

    public function getHint(): string
    {
        return 'Add descriptive alt text for meaningful images. For decorative images that add no information, use an empty alt attribute: alt="".';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $images = $dom->getElementsByTagName('img');

        foreach ($images as $image) {
            if (!$image instanceof \DOMElement) {
                continue;
            }

            $hasAlt = $image->hasAttribute('alt');
            $altValue = trim($image->getAttribute('alt'));
            $snippet = $this->elementSnippet($image);
            $path = $this->buildXPath($image);

            if (!$hasAlt) {
                $violations[] = new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: Severity::Critical,
                    message: 'Image has no alt attribute.',
                    hint: $this->getHint(),
                    contextSnippet: $snippet,
                    contextPath: $path,
                );

                continue;
            }

            if ($altValue === '') {
                // Empty alt is a valid WCAG pattern for intentionally decorative images.
                continue;
            }
        }

        return $violations;
    }
}
