<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Psr\Log\LoggerInterface;

final class RenderedHtmlAnalyzer
{
    public function __construct(
        private readonly RenderedHtmlRuleRegistry $ruleRegistry,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return RuleViolation[]
     */
    public function analyze(int $pageUid, int $languageUid, string $siteIdentifier, string $url, string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $context = new RenderedHtmlContext(
            pageUid: $pageUid,
            languageUid: $languageUid,
            siteIdentifier: $siteIdentifier,
            url: $url,
            html: $html,
            document: $dom,
            xpath: new \DOMXPath($dom),
        );

        $violations = [];
        foreach ($this->ruleRegistry->getRulesFor($context) as $rule) {
            if (!$this->ruleConfigurationService->isRuleEnabledForSite($siteIdentifier, $rule->getRuleId())) {
                continue;
            }

            try {
                foreach ($rule->evaluate($context) as $violation) {
                    $violations[] = $violation;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Rendered HTML rule failed', [
                    'ruleId' => $rule->getRuleId(),
                    'pageUid' => $pageUid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $violations;
    }
}
