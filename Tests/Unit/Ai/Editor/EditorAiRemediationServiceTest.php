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
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTargetResolver;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
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
use Priebera\A11yQualityGate\Tests\Unit\Ai\Usage\AiUsageRepositoryDouble;
use Priebera\A11yQualityGate\Rule\Rte\ButtonLabelMissingRule;
use Priebera\A11yQualityGate\Rule\Rte\HeadingHierarchyRule;
use Priebera\A11yQualityGate\Rule\Rte\IframeMissingTitleRule;
use Priebera\A11yQualityGate\Rule\Rte\ImgAltMissingRule;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiConfigurationRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class EditorAiRemediationServiceTest extends TestCase
{
    private const BUTTONS = '<form action="/newsletter?utm=x#top"><button type="submit"><svg class="icon-send"></svg></button></form>'
        . '<p>Second <button type="button"></button></p>';

    /** @var list<AiStructuredRequest> */
    private array $requests = [];
    /** @var list<array<string,mixed>> */
    private array $usageRows = [];
    /** @var array<string,string|bool> */
    private array $answer = ['status' => 'suggestion', 'value' => 'Subscribe to the newsletter', 'reason' => 'It submits the newsletter form.'];
    private InMemoryAiSiteSettingRepository $siteSettings;
    private InMemoryAiConfigurationRepository $configurations;
    /** @var array<string,mixed> */
    private array $tsConfig = [];
    private bool $isAdmin = false;
    private bool $isTranslation = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteSettings = new InMemoryAiSiteSettingRepository();
        $this->siteSettings->savePrivacy('main', true, false);
        $this->configurations = new InMemoryAiConfigurationRepository();
    }

    #[Test]
    public function aFixIsASuggestionForTheExactOccurrenceAndUsesTheTaskRunner(): void
    {
        $result = $this->service()->fix($this->input(self::BUTTONS, 'rte.button_label_missing', 'p > button', 1));

        self::assertSame('suggestion', $result['status']);
        self::assertSame('Subscribe to the newsletter', $result['value']);
        self::assertSame(1, $result['target']['occurrence']);
        self::assertSame(2, $result['target']['total']);
        self::assertTrue($result['resolvesFinding']);
        self::assertSame(['inputTokens' => 120, 'outputTokens' => 12, 'reported' => true], $result['usage']);
        self::assertCount(1, $this->requests);
        self::assertSame(AiTask::BUTTON_LABEL, $this->requests[0]->task);
        self::assertSame('button_label', $this->usageRows[0]['feature']);
        self::assertStringStartsWith(AiPromptDefinition::CONTEXT_WRAPPER_PREFIX, $this->requests[0]->inputText);
    }

    #[Test]
    public function pageTitlesAreNeitherSentNorFlaggedWhenTheSiteDisallowsThem(): void
    {
        $this->service()->fix($this->input(self::BUTTONS, 'rte.button_label_missing', 'form > button', 0));

        self::assertFalse($this->requests[0]->includesPageTitle);
        self::assertStringNotContainsString('page_title', $this->requests[0]->inputText);
        // Query strings and fragments of addresses never leave AQG.
        self::assertStringContainsString('action=/newsletter', $this->requests[0]->inputText);
        self::assertStringNotContainsString('utm=x', $this->requests[0]->inputText);
    }

    #[Test]
    public function imagesAreRefusedBeforeAnyProviderRequestWhenTheSiteDisallowsThem(): void
    {
        $this->siteSettings->savePrivacy('main', false, false);

        $this->assertRefused('privacy_images', fn () => $this->service()->fix($this->input('<p><img src="/a.png"></p>', 'rte.img_alt_missing', 'p > img', 0)));
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function aChangedOccurrenceIsStale(): void
    {
        try {
            $this->service()->fix($this->input(self::BUTTONS, 'rte.button_label_missing', 'p > button', 0));
            self::fail('A finding that now names another element must be refused.');
        } catch (EditorAiException $exception) {
            self::assertSame('stale', $exception->safeCode);
            self::assertSame(409, $exception->httpStatus);
        }
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function aFindingThatNoLongerExistsIsStale(): void
    {
        $this->assertRefused('stale', fn () => $this->service()->fix($this->input('<p><button type="button">Send</button></p>', 'rte.button_label_missing', 'p > button', 0)));
    }

    #[Test]
    public function unsupportedRulesAndDecodedMarkupAreRefused(): void
    {
        foreach ([
            [$this->input(self::BUTTONS, 'rte.table_missing_caption', 'table', 0), 'unsupported_rule'],
            [$this->input('<p>&lt;button&gt;&lt;/button&gt;</p>', 'rte.button_label_missing', 'button', 0), 'source_ambiguous'],
        ] as [$input, $code]) {
            try {
                $this->service()->fix($input);
                self::fail('Expected ' . $code);
            } catch (EditorAiException $exception) {
                self::assertSame($code, $exception->safeCode);
            }
        }
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function modelOutputWithMarkupIsAnInvalidResponseNotASuggestion(): void
    {
        $this->answer = ['status' => 'suggestion', 'value' => '<img src=x onerror=alert(1)>Send', 'reason' => 'r'];

        $result = $this->service()->fix($this->input(self::BUTTONS, 'rte.button_label_missing', 'p > button', 1));

        self::assertSame('invalid_response', $result['status']);
        self::assertSame('markup', $result['rejection']);
        self::assertSame('', $result['value']);
    }

    #[Test]
    public function needsReviewIsPassedOnWithoutAValue(): void
    {
        $this->answer = ['status' => 'needs_review', 'value' => 'Guess', 'reason' => 'The purpose is unclear.'];

        $result = $this->service()->fix($this->input(self::BUTTONS, 'rte.button_label_missing', 'p > button', 1));

        self::assertSame('needs_review', $result['status']);
        self::assertSame('', $result['value']);
        self::assertSame('The purpose is unclear.', $result['reason']);
    }

    #[Test]
    public function aValueTheRuleWouldStillReportIsFlagged(): void
    {
        $this->answer = ['status' => 'suggestion', 'value' => '   Send now ', 'reason' => 'r'];
        $result = $this->service()->fix($this->input(self::BUTTONS, 'rte.button_label_missing', 'p > button', 1));
        self::assertSame('Send now', $result['value']);
        self::assertSame([], $result['warnings']);
    }

    #[Test]
    public function iframeTitlesFollowTheSiteSwitchForLinkAndIframeSuggestions(): void
    {
        $html = '<p>Map</p><iframe src="https://maps.example/embed?q=x"></iframe>';
        try {
            $this->service()->fix($this->input($html, 'rte.iframe_missing_title', 'iframe', 0));
            self::fail('Expected the site switch to refuse.');
        } catch (EditorAiException $exception) {
            self::assertSame('link_text_disabled', $exception->safeCode);
        }

        $this->configurations->setLinkTextSuggestionsEnabled('main', true);
        $this->answer = ['status' => 'suggestion', 'value' => 'Map: customer centre', 'reason' => 'r'];
        $result = $this->service()->fix($this->input($html, 'rte.iframe_missing_title', 'iframe', 0));
        self::assertSame('Map: customer centre', $result['value']);
        self::assertStringContainsString('https://maps.example/embed', $this->requests[0]->inputText);
        self::assertStringNotContainsString('q=x', $this->requests[0]->inputText);
    }

    #[Test]
    public function explanationsOfLinksFollowTheSiteSwitchToo(): void
    {
        $html = '<p>Map</p><iframe src="https://maps.example/embed"></iframe>';
        $this->assertRefused('link_text_disabled', fn () => $this->service()->explain($this->input($html, 'rte.iframe_missing_title', 'iframe', 0)));
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function eMailAddressesInTheSurroundingTextAreMasked(): void
    {
        $this->service()->fix($this->input('<p>Write to jane.doe@example.com <button type="button"></button></p>', 'rte.button_label_missing', 'p > button', 0));

        self::assertStringNotContainsString('jane.doe@example.com', $this->requests[0]->inputText);
        self::assertStringContainsString('[e-mail address]', $this->requests[0]->inputText);
    }

    #[Test]
    public function headingLevelsHaveNoAiFixButCanBeExplained(): void
    {
        $html = '<h2>A</h2><h4>B</h4>';
        try {
            $this->service()->fix($this->input($html, 'rte.heading_hierarchy_jump', 'h4', 0));
            self::fail('Rule-based fixes never call AI.');
        } catch (EditorAiException $exception) {
            self::assertSame('rule_based', $exception->safeCode);
        }

        $this->answer = ['status' => 'explanation', 'explanation' => '<b>Screen reader</b> users navigate by headings.', 'how_to_fix' => 'Use H3.'];
        $result = $this->service()->explain($this->input($html, 'rte.heading_hierarchy_jump', 'h4', 0));
        self::assertSame('explanation', $result['status']);
        self::assertSame('Screen reader users navigate by headings.', $result['explanation']);
        self::assertSame(AiTask::EXPLAIN, $this->requests[0]->task);
    }

    #[Test]
    public function editorsWithoutTheEditorFlagGetNoAi(): void
    {
        $this->tsConfig = ['options.' => ['a11y_quality_gate.' => ['ai.' => ['editor' => '0']]]];

        $this->assertRefused('no_permission', fn () => $this->service()->fix($this->input(self::BUTTONS, 'rte.button_label_missing', 'p > button', 1)));
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function writingAidsNeedTheWritingFlagAndTranslationsATranslatedRecord(): void
    {
        try {
            $this->service()->write('tt_content', 1, 'bodytext', 'clarity', 'Pursuant to the regulations the price changes.');
            self::fail('Writing aids are off by default for editors.');
        } catch (EditorAiException $exception) {
            self::assertSame('no_permission', $exception->safeCode);
        }

        $this->tsConfig = ['options.' => ['a11y_quality_gate.' => ['ai.' => ['writing' => '1']]]];
        try {
            $this->service()->write('tt_content', 1, 'bodytext', 'translation', 'Hello');
            self::fail('Translation drafts need a translated record.');
        } catch (EditorAiException $exception) {
            self::assertSame('not_translation', $exception->safeCode);
        }

        $this->answer = ['status' => 'suggestion', 'text' => 'The price changes.', 'notes' => 'Shorter.'];
        $result = $this->service()->write('tt_content', 1, 'bodytext', 'clarity', 'Pursuant to the regulations the price changes.');
        self::assertSame('The price changes.', $result['text']);
        self::assertSame(AiTask::WRITING, $this->requests[0]->task);
        self::assertFalse($this->requests[0]->includesPageTitle);
    }

    #[Test]
    public function writingAidsRefuseMarkupInTheSelection(): void
    {
        $this->tsConfig = ['options.' => ['a11y_quality_gate.' => ['ai.' => ['writing' => '1']]]];
        $this->assertRefused('invalid_input', fn () => $this->service()->write('tt_content', 1, 'bodytext', 'clarity', 'Text <script>x</script>'));
        self::assertSame([], $this->requests);
    }

    #[Test]
    public function verificationChecksTheStoredRecord(): void
    {
        $stored = '<p><button type="submit" aria-label="Send">x</button><button type="button"></button></p>';
        $service = $this->service(['html' => $stored, 'tstamp' => 2000]);
        $item = ['ruleId' => 'rte.button_label_missing', 'tag' => 'button', 'attributes' => ['type' => 'submit', 'aria-label' => 'Send'], 'text' => 'x', 'suggestedAt' => 1999];

        self::assertSame(['saved' => true, 'results' => ['verified']], $service->verify('tt_content', 1, 'bodytext', [$item]));
        self::assertSame(['saved' => false, 'results' => ['not_saved']], $service->verify('tt_content', 1, 'bodytext', [['suggestedAt' => 2001] + $item]));
        // Without a server time nothing proves the change was saved: never "saved" by assumption.
        self::assertSame(['not_saved'], $service->verify('tt_content', 1, 'bodytext', [['suggestedAt' => 0] + $item])['results']);
        $still = ['ruleId' => 'rte.button_label_missing', 'tag' => 'button', 'attributes' => ['type' => 'button'], 'text' => '', 'suggestedAt' => 1999];
        $gone = ['ruleId' => 'rte.button_label_missing', 'tag' => 'button', 'attributes' => ['type' => 'submit', 'aria-label' => 'Other'], 'text' => 'x', 'suggestedAt' => 1999];
        self::assertSame(['saved' => true, 'results' => ['still_reported', 'not_found', 'not_saved']], $service->verify('tt_content', 1, 'bodytext', [$still, $gone, ['suggestedAt' => 2500] + $item]));
        // By position: an identical element elsewhere does not verify the applied one.
        self::assertSame(['verified'], $service->verify('tt_content', 1, 'bodytext', [['occurrence' => 0, 'total' => 2] + $item])['results']);
        self::assertSame(['not_found'], $service->verify('tt_content', 1, 'bodytext', [['occurrence' => 1, 'total' => 2] + $item])['results']);
        self::assertSame(['not_found'], $service->verify('tt_content', 1, 'bodytext', [['occurrence' => 0, 'total' => 3] + $item])['results']);
        // Without a position (remediation queue): an element that was there before the change never proves it.
        self::assertSame(['not_found'], $service->verify('tt_content', 1, 'bodytext', [['before' => 1] + $item])['results']);
        self::assertSame(['verified'], $service->verify('tt_content', 1, 'bodytext', [['before' => 0] + $item])['results']);
    }

    #[Test]
    public function anIdenticalElementAlreadyInTheFieldNeverVerifiesTheQueuedChange(): void
    {
        // The second button gets the label the first one has already; the queue verifies without a position.
        $html = '<p><button type="submit" aria-label="Send">x</button><button type="submit">x</button></p>';
        $target = new EditorFindingTarget('rte.button_label_missing', EditorAiRuleCatalog::KIND_BUTTON_LABEL, EditorAiRuleCatalog::OPERATION_ATTRIBUTE, 'aria-label', 'button', 1, 2, 'p > button[2]', ['type' => 'submit'], 'x', false, '');

        $signature = $this->service()->expectedSignature($html, $target, 'Send');
        self::assertSame(['aria-label' => 'Send', 'type' => 'submit'], $signature['attributes'] ?? null);
        self::assertSame(1, $signature['before'] ?? null);
        $item = ['occurrence' => -1, 'total' => -1, 'suggestedAt' => 1999] + $signature;

        // Saved without the change (an unrelated edit): only the old identical button exists.
        self::assertSame(['not_found'], $this->service(['html' => $html, 'tstamp' => 2000])->verify('tt_content', 1, 'bodytext', [$item])['results']);
        // Saved with the change.
        $changed = '<p><button type="submit" aria-label="Send">x</button><button type="submit" aria-label="Send">x</button></p>';
        self::assertSame(['verified'], $this->service(['html' => $changed, 'tstamp' => 2000])->verify('tt_content', 1, 'bodytext', [$item])['results']);
    }

    private function assertRefused(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Expected the request to be refused with ' . $code);
        } catch (EditorAiException $exception) {
            self::assertSame($code, $exception->safeCode);
        }
    }

    /** @return array{table:string,uid:int,field:string,html:string,ruleId:string,contextPath:string,occurrence:int} */
    private function input(string $html, string $ruleId, string $path, int $occurrence): array
    {
        return ['table' => 'tt_content', 'uid' => 1, 'field' => 'bodytext', 'html' => $html, 'ruleId' => $ruleId, 'contextPath' => $path, 'occurrence' => $occurrence];
    }

    /** @param array{html:string,tstamp:int}|null $stored */
    private function service(?array $stored = null): EditorAiRemediationService
    {
        $users = $this->createMock(BackendUserService::class);
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);
        $backendUser->method('getTSConfig')->willReturnCallback(fn (): array => $this->tsConfig);
        $backendUser->method('check')->willReturn(true);
        $users->method('getBackendUser')->willReturn($backendUser);
        $users->method('getBackendUserUid')->willReturn(7);

        $featureAccess = $this->createMock(AiFeatureAccessServiceInterface::class);
        $featureAccess->method('isAvailable')->willReturn(true);
        $configurationResolver = $this->createMock(AiConfigurationResolverInterface::class);
        $configurationResolver->method('resolve')->willReturn(new AiProviderConfiguration('openai', 'sk-test', 'gpt-5.4-mini', 'site', 'fp'));
        $configurationResolver->method('status')->willReturn(['provider' => 'openai', 'selectedModelId' => 'gpt-5.4-mini']);
        $siteSettingsService = new AiSiteSettingsService($this->siteSettings, $this->configurations);

        $recordResolver = $this->createMock(EditorAiRecordContextResolver::class);
        $recordResolver->method('resolve')->willReturnCallback(fn (): EditorAiRecordContext => new EditorAiRecordContext(
            'tt_content', 1, 'bodytext', 10, 'main', $this->isTranslation ? 1 : 0, 'en-US', 'English', 'en-US', 0, $this->isTranslation, 'text',
        ));
        $recordResolver->method('storedValue')->willReturn($stored ?? ['html' => '', 'tstamp' => 0]);

        $executor = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $executor->method('supports')->willReturn(true);
        $executor->method('execute')->willReturnCallback(function (AiStructuredRequest $request): AiStructuredResponse {
            $this->requests[] = $request;
            return new AiStructuredResponse($this->answer, new AiUsage(120, 12, true), 'openai', 'gpt-5.4-mini');
        });
        $limits = $this->createMock(AiRateLimitRepositoryInterface::class);
        $limits->method('consume')->willReturn(['allowed' => true, 'retryAfter' => 0]);
        $userInterface = $this->createMock(BackendUserServiceInterface::class);
        $userInterface->method('getBackendUserUid')->willReturn(7);
        $usage = new AiUsageRepositoryDouble(function (array $row): void {
            $this->usageRows[] = $row;
        });
        $runner = new AiStructuredTaskRunner(
            $featureAccess,
            $siteSettingsService,
            new AiRateLimiter($limits, $userInterface),
            $configurationResolver,
            new AiUsageRecorder($usage, new AiUsageMeter(), $userInterface),
            [$executor],
        );

        $rules = $this->createMock(RuleConfigurationService::class);
        $rules->method('isRuleEnabledForSite')->willReturn(true);

        return new EditorAiRemediationService(
            $recordResolver,
            new EditorAiAccessService($featureAccess, $configurationResolver, $siteSettingsService, $this->configurations, $users),
            new EditorFindingTargetResolver(),
            new EditorAiContextBuilder($this->createMock(ResourceFactory::class), $users),
            new EditorAiValueValidator(),
            $this->createMock(EditorAiImageResolver::class),
            $runner,
            $siteSettingsService,
            new RuleRegistry([new ButtonLabelMissingRule(), new ImgAltMissingRule(), new IframeMissingTitleRule(), new HeadingHierarchyRule()]),
            $rules,
        );
    }
}
