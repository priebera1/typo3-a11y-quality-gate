<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class MainLandmarkMissingRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.main_landmark_missing'; }
    public function getDefaultSeverity(): Severity { return Severity::NeedsReview; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('main') as $main) {
            if ($main instanceof \DOMElement && !$this->isInsideTemplate($main)) { return; }
        }

        $mainRoles = $context->xpath->query('//*[@role="main"]');
        if ($mainRoles !== false) {
            foreach ($mainRoles as $mainRole) {
                if ($mainRole instanceof \DOMElement && !$this->isInsideTemplate($mainRole)) { return; }
            }
        }

        $body = $context->document->getElementsByTagName('body')->item(0)
            ?: $context->document->getElementsByTagName('html')->item(0);
        if ($body instanceof \DOMElement) {
            yield $this->issueFactory->create($context, $body, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered page may be missing a main landmark.', 'This is a review item, not an automatic failure. Verify manually whether the page has a clear main landmark using <main> or role="main".');
        }
    }
}
