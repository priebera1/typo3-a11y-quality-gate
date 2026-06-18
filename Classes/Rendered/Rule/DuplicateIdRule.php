<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueFactory;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

final class DuplicateIdRule extends AbstractRenderedHtmlRule
{
    public function __construct(
        RenderedHtmlIssueFactory $issueFactory,
        private readonly RuleMetadataPresentationService $ruleMetadataPresentationService,
    ) {
        parent::__construct($issueFactory);
    }
    public function getRuleId(): string { return 'rendered.duplicate_id'; }
    public function getDefaultSeverity(): Severity { return Severity::Warning; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        $elementsById = [];
        $nodes = $context->xpath->query('//*[@id]');
        if ($nodes === false) { return; }

        foreach ($nodes as $element) {
            if (!$element instanceof \DOMElement || $this->isInsideTemplate($element)) { continue; }
            $id = trim($element->getAttribute('id'));
            if ($id === '') { continue; }
            $elementsById[$id][] = $element;
        }

        foreach ($elementsById as $id => $elements) {
            if (count($elements) < 2) { continue; }
            foreach ($elements as $element) {
                yield $this->issueFactory->create(
                    $context,
                    $element,
                    $this->getRuleId(),
                    $this->getDefaultSeverity(),
                    sprintf('%s: %s', $this->ruleMetadataPresentationService->friendlyTitleForRule($this->getRuleId(), '', 'en'), $id),
                    (string)($this->ruleMetadataPresentationService->present(['rule_id' => $this->getRuleId()], 'en')['howToFix'] ?? '')
                );
            }
        }
    }
}
