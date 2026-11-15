<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Editor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiContextBuilder;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiImageResolver;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRecordContext;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRecordContextResolver;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTargetResolver;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskExecutorInterface;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiRateLimitRepositoryInterface;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\EmptyLinkRule;
use Priebera\A11yQualityGate\Rule\Rte\LinkTextIsUrlOrFilenameRule;
use Priebera\A11yQualityGate\Rule\Rte\NonDescriptiveLinkRule;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\DictionaryRegistry;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiConfigurationRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Usage\AiUsageRepositoryDouble;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * AI Fixes for link text (rte.non_descriptive_link, rte.link_text_is_url_or_filename, rte.empty_link) with a
 * deterministic provider double: the exact link of the finding is targeted, only its text changes (destination,
 * attributes, the other links and the surrounding content stay), only the link's own block is sent, a decline keeps
 * its reason, and model output that cannot fix the finding is never offered for review.
 *
 * Live QA 2026-11-14 (typo3test14, finding 75325): `<p><a href="/detail">Viac o sluzbe</a> <a href="/info">read more</a></p>`
 * — the second link, whose destination does not exist (404) and has no context, was rightly declined.
 */
final class EditorAiLinkTextFixTest extends TestCase
{
    private const LIVE_FIXTURE = "<h2>\n    Spravny nadpis\n</h2>\n<p>\n    Toto je mix stranka.\n</p>\n<p>\n    <a href=\"/detail\">Viac o sluzbe</a> <a href=\"/info\">read more</a>\n</p>";
    private const CONTACT = '<p>Internal note: do not publish the price list.</p>'
        . '<p>Our bakery in Lindenhof is open Monday to Saturday. Directions, parking and our phone number: <a href="/contact?utm=mail#map" class="more" target="_self">click here</a>.</p>';

    /** @var list<AiStructuredRequest> */
    private array $requests = [];
    /** @var array<string,string|bool> */
    private array $answer = ['status' => 'suggestion', 'value' => 'Directions, parking and phone number', 'reason' => 'The link leads to the contact page.'];
    private InMemoryAiSiteSettingRepository $siteSettings;
    private InMemoryAiConfigurationRepository $configurations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteSettings = new InMemoryAiSiteSettingRepository();
        $this->siteSettings->savePrivacy('main', true, false);
        $this->configurations = new InMemoryAiConfigurationRepository();
        $this->configurations->setLinkTextSuggestionsEnabled('main', true);
    }

    #[Test]
    public function aGenericLinkWithEnoughContextGetsAReviewableSuggestionForExactlyThatLink(): void
    {
        $result = $this->service()->fix($this->finding(self::CONTACT, 'rte.non_descriptive_link', 0));

        self::assertSame('suggestion', $result['status']);
        self::assertSame('Directions, parking and phone number', $result['value']);
        self::assertSame('click here', $result['original']);
        self::assertTrue($result['resolvesFinding']);
        self::assertSame([], $result['warnings']);
        self::assertSame('link_text', $result['target']['kind']);
        self::assertCount(1, $this->requests);
        self::assertSame(AiTask::LINK_TEXT, $this->requests[0]->task);

        $sent = $this->requests[0]->inputText;
        self::assertStringContainsString('/contact', $sent);
        self::assertStringContainsString('Directions, parking and our phone number', $sent);
        // Only the link's own block: no other paragraph, no query string, no fragment.
        self::assertStringNotContainsString('price list', $sent);
        self::assertStringNotContainsString('utm=mail', $sent);
        self::assertStringNotContainsString('#map', $sent);
    }

    #[Test]
    public function onlyTheTextOfTheTargetLinkChangesAndEverythingElseIsKept(): void
    {
        $changed = $this->changedHtml(self::LIVE_FIXTURE, 'rte.non_descriptive_link', 'Information about the service');

        self::assertStringContainsString('<a href="/info">Information about the service</a>', $changed);
        self::assertStringContainsString('<a href="/detail">Viac o sluzbe</a>', $changed);
        self::assertStringContainsString('Spravny nadpis', $changed);
        self::assertStringContainsString('Toto je mix stranka.', $changed);
        self::assertStringNotContainsString('read more', $changed);

        $withAttributes = $this->changedHtml(self::CONTACT, 'rte.non_descriptive_link', 'Directions and parking');
        self::assertStringContainsString('<a href="/contact?utm=mail#map" class="more" target="_self">Directions and parking</a>', $withAttributes);
        self::assertStringContainsString('Internal note: do not publish the price list.', $withAttributes);
    }

    #[Test]
    public function theLiveFixtureTargetsTheSecondLinkAndAnAmbiguousDestinationIsDeclinedWithItsReason(): void
    {
        $this->answer = ['status' => 'needs_review', 'value' => '', 'reason' => "The link target is only '/info', so its topic cannot be determined safely."];

        $input = $this->finding(self::LIVE_FIXTURE, 'rte.non_descriptive_link', 0);
        // The stored finding 75325 on typo3test14: context path "p[2] > a[2]", the second <a> of the field.
        self::assertSame('p[2] > a[2]', $input['contextPath']);
        self::assertSame(1, $input['occurrence']);
        // "Viac o sluzbe" is descriptive: the rule reports only "read more".
        self::assertCount(1, $this->violations(self::LIVE_FIXTURE, 'rte.non_descriptive_link'));
        $result = $this->service()->fix($input);

        self::assertSame('needs_review', $result['status']);
        self::assertSame('', $result['value']);
        self::assertSame("The link target is only '/info', so its topic cannot be determined safely.", $result['reason']);
        self::assertSame('read more', $result['original']);
        self::assertSame(1, $result['target']['occurrence']);
        self::assertSame(2, $result['target']['total']);
        self::assertSame(['href' => '/info'], (array)$result['target']['element']['attributes']);
        self::assertSame('text', $result['target']['operation']);
        self::assertSame('p[2] > a[2]', $result['target']['contextPath']);
        // The model saw the destination and the block, nothing it would need to invent.
        self::assertStringContainsString('/info', $this->requests[0]->inputText);
        self::assertStringContainsString('Viac o sluzbe read more', $this->requests[0]->inputText);
    }

    #[Test]
    public function aGenericReplacementIsNeverOfferedForReview(): void
    {
        $this->answer = ['status' => 'suggestion', 'value' => 'Click here', 'reason' => 'r'];

        $result = $this->service()->fix($this->finding(self::LIVE_FIXTURE, 'rte.non_descriptive_link', 0));

        self::assertSame('invalid_response', $result['status']);
        self::assertSame('still_reported', $result['rejection']);
        self::assertSame('', $result['value']);
        self::assertSame('', $result['reason']);
    }

    /**
     * Review 2026-11-14: the rule reads a non-empty aria-label before the visible text. A text change cannot fix such a
     * link, so its value is not refused but kept with the still_reported warning (the editor also fixes the label).
     */
    #[Test]
    public function aLinkWithAGenericAriaLabelKeepsTheValueWithAWarning(): void
    {
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];

        $result = $this->service()->fix($this->finding('<p>Directions and parking: <a href="/contact" aria-label="click here">click here</a></p>', 'rte.non_descriptive_link', 0));

        self::assertSame('suggestion', $result['status']);
        self::assertSame('Directions and parking', $result['value']);
        self::assertFalse($result['resolvesFinding']);
        self::assertSame(['still_reported'], $result['warnings']);
    }

    #[Test]
    public function unsafeModelOutputIsRejectedAndKeepsNoReason(): void
    {
        foreach ([
            '<a href="/x">More about the service</a>' => 'markup',
            'https://example.com/info' => 'url',
            'read more' => 'unchanged',
            str_repeat('Information ', 20) => 'too_long',
            '' => 'empty',
        ] as $value => $rejection) {
            $this->answer = ['status' => 'suggestion', 'value' => (string)$value, 'reason' => 'Model reason that must not be kept.'];
            $result = $this->service()->fix($this->finding(self::LIVE_FIXTURE, 'rte.non_descriptive_link', 0));

            self::assertSame('invalid_response', $result['status'], (string)$value);
            self::assertSame($rejection, $result['rejection'], (string)$value);
            self::assertSame('', $result['value'], (string)$value);
            self::assertSame('', $result['reason'], (string)$value);
        }
    }

    #[Test]
    public function aUrlAsLinkTextAndAnEmptyLinkAreFixedWithTheirOwnRules(): void
    {
        $this->answer = ['status' => 'suggestion', 'value' => 'Annual report 2025 (PDF)', 'reason' => 'r'];
        $result = $this->service()->fix($this->finding('<p>Download: <a href="/files/report-2025.pdf">report-2025.pdf</a></p>', 'rte.link_text_is_url_or_filename', 0));
        self::assertSame('suggestion', $result['status']);
        self::assertTrue($result['resolvesFinding']);

        $this->answer = ['status' => 'suggestion', 'value' => 'Contact form', 'reason' => 'r'];
        $result = $this->service()->fix($this->finding('<p>Write to us using the contact form: <a href="/contact-form"></a></p>', 'rte.empty_link', 0));
        self::assertSame('suggestion', $result['status']);
        self::assertSame('', $result['original']);
        self::assertTrue($result['resolvesFinding']);
    }

    #[Test]
    public function aLinkWithNestedMarkupIsLeftToTheEditor(): void
    {
        try {
            $this->service()->fix($this->finding('<p><a href="/info"><strong>read</strong> more</a></p>', 'rte.non_descriptive_link', 0));
            self::fail('A link whose text is not plain text must not be rewritten.');
        } catch (EditorAiException $exception) {
            self::assertContains($exception->safeCode, ['manual_only', 'unsupported', 'stale']);
        }
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function linkSuggestionsFollowTheSiteSwitch(): void
    {
        $this->configurations->setLinkTextSuggestionsEnabled('main', false);
        try {
            $this->service()->fix($this->finding(self::CONTACT, 'rte.non_descriptive_link', 0));
            self::fail('Expected the site switch to refuse.');
        } catch (EditorAiException $exception) {
            self::assertSame('link_text_disabled', $exception->safeCode);
        }
        self::assertSame([], $this->requests);
    }

    /**
     * The fix input of the $index-th finding of $ruleId in $html, exactly as a stored finding carries it (the rule's own
     * context path and the element's occurrence).
     *
     * @return array{table:string,uid:int,field:string,html:string,ruleId:string,contextPath:string,occurrence:int}
     */
    private function finding(string $html, string $ruleId, int $index): array
    {
        $violation = $this->violations($html, $ruleId)[$index] ?? null;
        self::assertNotNull($violation, 'The rule reports finding #' . $index);
        $target = (new EditorFindingTargetResolver())->resolve($html, $violation);
        self::assertNotNull($target);

        return ['table' => 'tt_content', 'uid' => 939, 'field' => 'bodytext', 'html' => $html, 'ruleId' => $ruleId, 'contextPath' => $violation->contextPath, 'occurrence' => $target->occurrence];
    }

    /** @return list<\Priebera\A11yQualityGate\Rule\RuleViolation> */
    private function violations(string $html, string $ruleId): array
    {
        $rule = $this->rules()->getById($ruleId);
        self::assertNotNull($rule);

        return array_values($rule->check(new CheckContext(siteIdentifier: 'main', pageUid: 730, sourceLangUid: 0, sourceTable: 'tt_content', sourceUid: 939, sourceField: 'bodytext', content: $html, cType: 'text')));
    }

    /** The field after applying $value to the last finding of $ruleId, through the service's own write path. */
    private function changedHtml(string $html, string $ruleId, string $value): string
    {
        $violations = $this->violations($html, $ruleId);
        self::assertNotSame([], $violations);
        $target = (new EditorFindingTargetResolver())->resolve($html, $violations[array_key_last($violations)]);
        self::assertNotNull($target);

        $method = new \ReflectionMethod(EditorAiRemediationService::class, 'changedHtml');
        $changed = $method->invoke($this->service(), $html, $target, $value);
        self::assertIsString($changed);
        return $changed;
    }

    private function rules(): RuleRegistry
    {
        $dictionary = $this->createMock(DictionaryRegistry::class);
        $dictionary->method('resolveForContext')->willReturn(['click here', 'read more', 'more', 'here']);

        return new RuleRegistry([new NonDescriptiveLinkRule($dictionary), new LinkTextIsUrlOrFilenameRule(), new EmptyLinkRule()]);
    }

    private function service(): EditorAiRemediationService
    {
        $users = $this->createMock(BackendUserService::class);
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getTSConfig')->willReturn([]);
        $backendUser->method('check')->willReturn(true);
        $users->method('getBackendUser')->willReturn($backendUser);
        $users->method('getBackendUserUid')->willReturn(7);

        $featureAccess = $this->createMock(AiFeatureAccessServiceInterface::class);
        $featureAccess->method('isAvailable')->willReturn(true);
        $configurationResolver = $this->createMock(AiConfigurationResolverInterface::class);
        $configurationResolver->method('resolve')->willReturn(new AiProviderConfiguration('anthropic', 'sk-test', 'claude-haiku-5-5', 'site', 'fp'));
        $configurationResolver->method('status')->willReturn(['provider' => 'anthropic', 'selectedModelId' => 'claude-haiku-5-5']);
        $siteSettingsService = new AiSiteSettingsService($this->siteSettings, $this->configurations);

        $recordResolver = $this->createMock(EditorAiRecordContextResolver::class);
        $recordResolver->method('resolve')->willReturn(new EditorAiRecordContext('tt_content', 939, 'bodytext', 730, 'main', 0, 'en-US', 'English', 'en-US', 0, false, 'text'));
        $recordResolver->method('storedValue')->willReturn(['html' => '', 'tstamp' => 0]);

        $executor = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $executor->method('supports')->willReturn(true);
        $executor->method('execute')->willReturnCallback(function (AiStructuredRequest $request): AiStructuredResponse {
            $this->requests[] = $request;
            return new AiStructuredResponse($this->answer, new AiUsage(120, 12, true), 'anthropic', 'claude-haiku-5-5');
        });
        $limits = $this->createMock(AiRateLimitRepositoryInterface::class);
        $limits->method('consume')->willReturn(['allowed' => true, 'retryAfter' => 0]);
        $userInterface = $this->createMock(BackendUserServiceInterface::class);
        $userInterface->method('getBackendUserUid')->willReturn(7);
        $runner = new AiStructuredTaskRunner(
            $featureAccess,
            $siteSettingsService,
            new AiRateLimiter($limits, $userInterface),
            $configurationResolver,
            new AiUsageRecorder(new AiUsageRepositoryDouble(static function (): void {}), new AiUsageMeter(), $userInterface),
            [$executor],
        );
        $ruleConfiguration = $this->createMock(RuleConfigurationService::class);
        $ruleConfiguration->method('isRuleEnabledForSite')->willReturn(true);

        return new EditorAiRemediationService(
            $recordResolver,
            new EditorAiAccessService($featureAccess, $configurationResolver, $siteSettingsService, $this->configurations, $users),
            new EditorFindingTargetResolver(),
            new EditorAiContextBuilder($this->createMock(ResourceFactory::class), $users),
            new EditorAiValueValidator(),
            $this->createMock(EditorAiImageResolver::class),
            $runner,
            $siteSettingsService,
            $this->rules(),
            $ruleConfiguration,
        );
    }
}
