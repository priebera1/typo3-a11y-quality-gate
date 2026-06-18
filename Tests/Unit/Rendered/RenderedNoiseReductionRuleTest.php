<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedFrontendUrlSanitizer;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueFactory;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueMapper;
use Priebera\A11yQualityGate\Rendered\Rule\DuplicateIdRule;
use Priebera\A11yQualityGate\Rendered\Rule\FormControlMissingLabelRule;
use Priebera\A11yQualityGate\Rendered\Rule\IframeMissingTitleRule;
use Priebera\A11yQualityGate\Rendered\Rule\SvgMissingAccessibleNameRule;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

final class RenderedNoiseReductionRuleTest extends TestCase
{
    #[Test]
    public function hiddenNoscriptTrackingIframeDoesNotCreateIssue(): void
    {
        $violations = $this->evaluateIframe('<noscript><iframe src="https://tracking.example.invalid/ns.html?id=TRACKING-123" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>');

        self::assertCount(0, $violations);
    }

    #[Test]
    public function visibleIframeWithoutTitleStillCreatesIssue(): void
    {
        $violations = $this->evaluateIframe('<iframe src="https://example.org/embed"></iframe>');

        self::assertCount(1, $violations);
        self::assertSame('rendered.iframe_missing_title', $violations[0]->ruleId);
    }

    #[Test]
    public function svgSpriteDefinitionContainerDoesNotCreateIssue(): void
    {
        $violations = $this->evaluateSvg('<svg xmlns="http://www.w3.org/2000/svg"><symbol id="icon" viewBox="0 0 16 16"><title>icon</title><path d="M0 0h16v16H0z" /></symbol></svg>');

        self::assertCount(0, $violations);
    }

    #[Test]
    public function svgInsideTemplateDoesNotCreateIssue(): void
    {
        $violations = $this->evaluateSvg('<template><svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z" /></svg></template>');

        self::assertCount(0, $violations);
    }

    #[Test]
    public function visibleSvgWithoutNameStillCreatesIssue(): void
    {
        $violations = $this->evaluateSvg('<svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z" /></svg>');

        self::assertCount(1, $violations);
        self::assertSame('rendered.svg_missing_accessible_name', $violations[0]->ruleId);
    }

    #[Test]
    public function svgInsideNamedInteractiveElementDoesNotCreateDuplicateSvgIssue(): void
    {
        $violations = $this->evaluateSvg('<a href="/" aria-label="Home"><svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z" /></svg></a>');

        self::assertCount(0, $violations);
    }

    #[Test]
    public function duplicateIdInsideTemplateIsIgnored(): void
    {
        $violations = $this->evaluateDuplicateId('<nav id="nav"></nav><template><nav id="nav"></nav></template>');

        self::assertCount(0, $violations);
    }

    #[Test]
    public function duplicateIdOutsideTemplateStillCreatesIssue(): void
    {
        $violations = $this->evaluateDuplicateId('<nav id="nav"></nav><nav id="nav"></nav>');

        self::assertCount(2, $violations);
        self::assertSame('rendered.duplicate_id', $violations[0]->ruleId);
    }

    #[Test]
    public function hiddenHelperInputDoesNotCreateIssue(): void
    {
        $violations = $this->evaluateFormControl('<input class="form-control js-helper is-hidden" type="text" name="helper">');

        self::assertCount(0, $violations);
    }

    #[Test]
    public function hiddenAndDNoneHelperInputsDoNotCreateIssues(): void
    {
        self::assertCount(0, $this->evaluateFormControl('<input class="form-control hidden" type="text" name="helper">'));
        self::assertCount(0, $this->evaluateFormControl('<input class="form-control d-none" type="text" name="helper">'));
    }

    #[Test]
    public function visuallyHiddenClassIsNotPartOfHiddenControlAllowlist(): void
    {
        $violations = $this->evaluateFormControl('<input class="form-control visually-hidden" type="text" name="still-visible-for-rule-purposes">');

        self::assertCount(1, $violations);
        self::assertSame('rendered.form_control_missing_label', $violations[0]->ruleId);
    }

    #[Test]
    public function hiddenClassDoesNotHideNonFormRulesGlobally(): void
    {
        $violations = $this->evaluateSvg('<svg class="is-hidden" viewBox="0 0 16 16"><path d="M0 0h16v16H0z" /></svg>');

        self::assertCount(1, $violations);
    }

    #[Test]
    public function visibleInputWithoutLabelStillCreatesIssue(): void
    {
        $violations = $this->evaluateFormControl('<input type="text" name="query">');

        self::assertCount(1, $violations);
        self::assertSame('rendered.form_control_missing_label', $violations[0]->ruleId);
    }

    #[Test]
    public function visibleSelectWithoutLabelStillCreatesIssue(): void
    {
        $violations = $this->evaluateFormControl('<select name="topic"><option>General</option></select>');

        self::assertCount(1, $violations);
        self::assertSame('rendered.form_control_missing_label', $violations[0]->ruleId);
    }

    private function evaluateIframe(string $bodyHtml): array
    {
        return iterator_to_array((new IframeMissingTitleRule($this->issueFactory()))->evaluate($this->context($bodyHtml)), false);
    }

    private function evaluateSvg(string $bodyHtml): array
    {
        return iterator_to_array((new SvgMissingAccessibleNameRule($this->issueFactory()))->evaluate($this->context($bodyHtml)), false);
    }

    private function evaluateFormControl(string $bodyHtml): array
    {
        return iterator_to_array((new FormControlMissingLabelRule($this->issueFactory()))->evaluate($this->context($bodyHtml)), false);
    }

    private function evaluateDuplicateId(string $bodyHtml): array
    {
        return iterator_to_array((new DuplicateIdRule(
            $this->issueFactory(),
            $this->ruleMetadataPresentationService()
        ))->evaluate($this->context($bodyHtml)), false);
    }

    private function context(string $bodyHtml): RenderedHtmlContext
    {
        $html = '<!doctype html><html lang="en"><head><title>Test</title></head><body>' . $bodyHtml . '</body></html>';
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new RenderedHtmlContext(
            pageUid: 1,
            languageUid: 0,
            siteIdentifier: 'test',
            url: 'https://example.org/page',
            html: $html,
            document: $document,
            xpath: new \DOMXPath($document),
        );
    }

    private function ruleMetadataPresentationService(): RuleMetadataPresentationService
    {
        $service = $this->createStub(RuleMetadataPresentationService::class);
        $service->method('friendlyTitleForRule')->willReturn('Duplicate ID');
        $service->method('present')->willReturn([
            'howToFix' => 'Use a unique ID for each element.',
        ]);

        return $service;
    }

    private function issueFactory(): RenderedHtmlIssueFactory
    {
        return new RenderedHtmlIssueFactory(new RenderedHtmlIssueMapper(), new RenderedFrontendUrlSanitizer());
    }
}
