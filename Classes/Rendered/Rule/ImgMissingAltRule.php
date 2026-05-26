<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class ImgMissingAltRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.img_missing_alt'; }
    public function getDefaultSeverity(): Severity { return Severity::Critical; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('img') as $img) {
            if (!$img instanceof \DOMElement || $this->isAriaHidden($img)) { continue; }
            if (!$img->hasAttribute('alt')) {
                yield $this->issueFactory->create($context, $img, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered image has no alt attribute.', 'Add descriptive alt text for meaningful images. For decorative images, use alt="".');
            }
        }
    }
}
