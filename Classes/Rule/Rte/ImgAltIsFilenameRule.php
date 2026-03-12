<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class ImgAltIsFilenameRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.img_alt_is_filename';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Image alt text looks like a file name.';
    }

    public function getHint(): string
    {
        return 'Replace the file name with a meaningful text alternative.';
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

            $alt = $this->normalizedText($image->getAttribute('alt'));
            if ($alt === '') {
                continue;
            }

            if (!$this->looksLikeFilename($alt)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($image),
                contextPath: $this->buildXPath($image),
            );
        }

        return $violations;
    }

    private function looksLikeFilename(string $value): bool
    {
        return (bool)preg_match(
            '/^[^\/\\\\]+\.(jpg|jpeg|png|gif|webp|svg|avif|bmp|tiff?)$/i',
            trim($value)
        );
    }
}
