<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueFactory;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

final class LandmarkUniqueRule extends AbstractRenderedHtmlRule
{
    public function __construct(
        RenderedHtmlIssueFactory $issueFactory,
        private readonly RuleMetadataPresentationService $ruleMetadataPresentationService,
    ) {
        parent::__construct($issueFactory);
    }
    public function getRuleId(): string
    {
        return 'rendered.landmark_unique';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::NeedsReview;
    }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        /** @var array<string, list<array{element:\DOMElement,displayName:string,comparisonKey:string}>> $landmarks */
        $landmarks = [];
        $nodes = $context->xpath->query('//*');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $element) {
            if (!$element instanceof \DOMElement
                || $this->isInsideTemplate($element)
                || $this->isRenderedHidden($element, false, true)
            ) {
                continue;
            }

            $type = $this->resolveLandmarkType($element);
            if ($type === null) {
                continue;
            }

            $displayName = $this->resolveLandmarkName($element, $context->xpath);
            $landmarks[$type][] = [
                'element' => $element,
                'displayName' => $displayName,
                'comparisonKey' => mb_strtolower($displayName),
            ];
        }

        foreach ($landmarks as $type => $items) {
            if (count($items) < 2) {
                continue;
            }

            $nameCounts = [];
            foreach ($items as $item) {
                $nameCounts[$item['comparisonKey']] = ($nameCounts[$item['comparisonKey']] ?? 0) + 1;
            }

            foreach ($items as $item) {
                $comparisonKey = $item['comparisonKey'];
                $displayName = $item['displayName'];
                if ($comparisonKey !== '' && ($nameCounts[$comparisonKey] ?? 0) < 2) {
                    continue;
                }

                $message = $comparisonKey === ''
                    ? sprintf('A %s landmark has no accessible name while multiple landmarks of this type are present.', $type)
                    : sprintf('Multiple %s landmarks use the same accessible name "%s".', $type, $displayName);

                yield $this->issueFactory->create(
                    $context,
                    $item['element'],
                    $this->getRuleId(),
                    $this->getDefaultSeverity(),
                    $message,
                    (string)($this->ruleMetadataPresentationService->present(['rule_id' => $this->getRuleId()], 'en')['howToFix'] ?? '')
                );
            }
        }
    }

    private function resolveLandmarkType(\DOMElement $element): ?string
    {
        $explicitRole = strtolower(trim($element->getAttribute('role')));
        if ($explicitRole !== '') {
            return match ($explicitRole) {
                'main', 'navigation', 'complementary', 'region', 'search', 'form', 'banner', 'contentinfo' => $explicitRole,
                default => null,
            };
        }

        return match (strtolower($element->tagName)) {
            'main' => 'main',
            'nav' => 'navigation',
            'aside' => 'complementary',
            'form' => $this->resolveLandmarkName($element, new \DOMXPath($element->ownerDocument)) !== '' ? 'form' : null,
            'header' => $this->isTopLevelSectioningElement($element) ? 'banner' : null,
            'footer' => $this->isTopLevelSectioningElement($element) ? 'contentinfo' : null,
            default => null,
        };
    }

    private function isTopLevelSectioningElement(\DOMElement $element): bool
    {
        $node = $element->parentNode;
        while ($node instanceof \DOMElement) {
            if (in_array(strtolower($node->tagName), ['article', 'aside', 'main', 'nav', 'section'], true)) {
                return false;
            }
            $node = $node->parentNode;
        }

        return true;
    }

    private function resolveLandmarkName(\DOMElement $element, \DOMXPath $xpath): string
    {
        $labelledBy = $this->resolveAriaLabelledByText($element, $xpath);
        if ($labelledBy !== '') {
            return $this->normalizedText($labelledBy);
        }

        $ariaLabel = $this->normalizedText($element->getAttribute('aria-label'));
        if ($ariaLabel !== '') {
            return $ariaLabel;
        }

        return '';
    }
}
