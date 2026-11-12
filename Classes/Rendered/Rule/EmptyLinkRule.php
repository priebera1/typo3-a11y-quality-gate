<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class EmptyLinkRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.empty_link'; }
    public function getDefaultSeverity(): Severity { return Severity::Critical; }

    private function isInteractiveAnchor(\DOMElement $link): bool
    {
        if ($link->hasAttribute('href')) {
            return true;
        }
        $role = strtolower(trim($link->getAttribute('role')));
        if (in_array($role, ['link', 'button', 'menuitem', 'tab', 'option', 'switch'], true)) {
            return true;
        }

        return $link->hasAttribute('tabindex') || $link->hasAttribute('onclick');
    }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('a') as $link) {
            if (!$link instanceof \DOMElement || $this->isInsideTemplate($link) || $this->isAriaHidden($link)) { continue; }
            // D-02: `<a id="c34"></a>` (fluid_styled_content's anchor for translated records) is a placeholder, not a
            // link: without href, an interactive role, tabindex or a click handler nothing can be activated.
            if (!$this->isInteractiveAnchor($link)) { continue; }
            if (!$this->hasAccessibleName($link, $context->xpath)) {
                yield $this->issueFactory->create($context, $link, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered link has no accessible name.', 'Add visible link text or an accessible label that describes the destination.');
            }
        }
    }
}
