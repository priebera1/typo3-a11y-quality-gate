<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class ImgAltTooLongRule extends AbstractRteRule
{
    public function __construct(
        private readonly int $defaultMaxLength = 120,
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.img_alt_too_long';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Image alternative text is too long.';
    }

    public function getHint(): string
    {
        return sprintf('Keep alt text concise. Move longer descriptions into surrounding content where possible. Default maximum: %d characters.', $this->defaultMaxLength);
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);

        foreach ($dom->getElementsByTagName('img') as $image) {
            if (!$image instanceof \DOMElement || !$image->hasAttribute('alt')) {
                continue;
            }

            $alt = trim($image->getAttribute('alt'));
            if ($alt === '') {
                continue;
            }

            $length = mb_strlen($alt);
            if ($length <= $this->defaultMaxLength) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Image alt text has %d characters. Recommended maximum is %d.', $length, $this->defaultMaxLength),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($image),
                contextPath: $this->buildXPath($image),
            );
        }

        return $violations;
    }
}
