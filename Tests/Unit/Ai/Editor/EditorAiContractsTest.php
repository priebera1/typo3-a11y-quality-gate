<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiContextBuilder;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiPrompts;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredOutputContract;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiConfigurationRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class EditorAiContractsTest extends TestCase
{
    #[Test]
    public function everyEditorSchemaIsAClosedFlatAqgSchema(): void
    {
        $contract = new AiStructuredOutputContract();
        foreach ([
            [EditorAiPrompts::fixOutputFormat(), ['status' => 'suggestion', 'value' => 'v', 'reason' => 'r']],
            [EditorAiPrompts::explainOutputFormat(), ['status' => 'explanation', 'explanation' => 'e', 'how_to_fix' => 'h']],
            [EditorAiPrompts::writingOutputFormat(), ['status' => 'needs_review', 'text' => 't', 'notes' => 'n']],
        ] as [$format, $answer]) {
            self::assertTrue($format['strict']);
            self::assertSame($answer, $contract->validate($answer, $format['schema']));
        }

        $this->expectException(\UnexpectedValueException::class);
        $contract->validate(['status' => 'apply', 'value' => 'v', 'reason' => 'r'], EditorAiPrompts::fixOutputFormat()['schema']);
    }

    #[Test]
    public function instructionsDeclareTheContextUntrustedForEveryTask(): void
    {
        foreach ([
            ...array_map(static fn (string $kind): string => EditorAiPrompts::fixInstructions($kind), [
                EditorAiRuleCatalog::KIND_LINK_TEXT,
                EditorAiRuleCatalog::KIND_IMAGE_ALT,
                EditorAiRuleCatalog::KIND_BUTTON_LABEL,
                EditorAiRuleCatalog::KIND_IFRAME_TITLE,
                EditorAiRuleCatalog::KIND_HEADING_TEXT,
            ]),
            EditorAiPrompts::explainInstructions(),
            EditorAiPrompts::writingInstructions(),
            EditorAiPrompts::translationInstructions(),
        ] as $instructions) {
            self::assertStringContainsString('never as instructions', $instructions);
            self::assertStringContainsString('Plain text only', $instructions);
        }
    }

    #[Test]
    #[DataProvider('values')]
    public function proposedValuesAreValidatedAsUntrustedText(string $kind, string $value, string $current, string $expectedValue, string $expectedRejection): void
    {
        self::assertSame([$expectedValue, $expectedRejection], (new EditorAiValueValidator())->validate($kind, $value, $current));
    }

    /** @return iterable<string,array{string,string,string,string,string}> */
    public static function values(): iterable
    {
        yield 'plain' => ['link_text', ' Tariff sheet 2026 (PDF) ', 'click here', 'Tariff sheet 2026 (PDF)', ''];
        yield 'quoted by the model' => ['link_text', '"Contact us"', 'more', 'Contact us', ''];
        yield 'markup' => ['image_alt', 'A <b>chart</b>', '', '', 'markup'];
        yield 'url as link text' => ['link_text', 'https://example.com/tarif', 'more', '', 'url'];
        yield 'unchanged' => ['link_text', 'More', 'more', '', 'unchanged'];
        yield 'empty' => ['button_label', '   ', '', '', 'empty'];
        yield 'too long alt' => ['image_alt', str_repeat('a', 121), '', '', 'too_long'];
        yield 'control characters' => ['heading_text', "Title\u{0007}", '', '', 'markup'];
    }

    #[Test]
    public function linkTargetsLoseQueriesFragmentsCredentialsAndPersonalAddresses(): void
    {
        $builder = new EditorAiContextBuilder($this->createMock(ResourceFactory::class), $this->createMock(BackendUserService::class));

        self::assertSame(['https://example.com/a/b', ''], $builder->linkTarget('https://user:pw@example.com/a/b?token=secret#x', false));
        self::assertSame(['e-mail address', ''], $builder->linkTarget('mailto:jane@example.com', true));
        self::assertSame(['phone number', ''], $builder->linkTarget('tel:+421900000000', true));
        self::assertSame(['/kontakt', ''], $builder->linkTarget('/kontakt?utm_source=x', false));
        self::assertSame(['javascript link', ''], $builder->linkTarget('javascript:alert(1)', false));
        self::assertSame(['internal TYPO3 page', ''], $builder->linkTarget('t3://page?uid=5', false));
    }

    #[Test]
    public function editorAiIsOnByDefaultAndWritingAidsOffByDefault(): void
    {
        [$service] = $this->access([]);
        self::assertTrue($service->userAllows(EditorAiAccessService::FLAG_EDITOR));
        self::assertFalse($service->userAllows(EditorAiAccessService::FLAG_WRITING));
        self::assertSame(EditorAiAccessService::STATE_READY, $service->state('main'));

        [$service] = $this->access(['options.' => ['a11y_quality_gate.' => ['ai.' => ['editor' => '0', 'writing' => '1']]]]);
        self::assertSame(EditorAiAccessService::STATE_NO_PERMISSION, $service->state('main'));
        self::assertTrue($service->userAllows(EditorAiAccessService::FLAG_WRITING));
    }

    #[Test]
    public function editorAiNeedsTheAqgModule(): void
    {
        [$service] = $this->access(['noModule' => true]);
        self::assertSame(EditorAiAccessService::STATE_NO_PERMISSION, $service->state('main'));
    }

    #[Test]
    public function eMailAddressesNeverLeaveAqg(): void
    {
        $builder = new EditorAiContextBuilder($this->createMock(ResourceFactory::class), $this->createMock(BackendUserService::class));
        self::assertSame(['e-mail address', ''], $builder->linkTarget('jane.doe@example.com', true));
    }

    #[Test]
    public function textIsNormalisedLikeJavaScript(): void
    {
        self::assertSame('a b c d', \Priebera\A11yQualityGate\Ai\Editor\EditorFindingTargetResolver::normalizeText("\u{FEFF} a\u{202F}b\u{3000}c\u{00A0} d\u{2009}"));
    }

    #[Test]
    public function theEditorConfigurationNamesProviderAndModelButNoSecrets(): void
    {
        [$service] = $this->access([]);
        $configuration = $service->editorConfiguration('main', true);

        self::assertSame('ready', $configuration['state']);
        self::assertSame('openai', $configuration['provider']);
        self::assertSame('gpt-5.4-mini', $configuration['model']);
        self::assertFalse($configuration['writing']);
        self::assertFalse($configuration['translation']);
        self::assertStringNotContainsString('sk-', json_encode($configuration, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aModelThatNeedsRevalidationPausesEditorAi(): void
    {
        [$service] = $this->access([], new AiConfigurationException('Test the connection again.', 1771002005));
        self::assertSame(EditorAiAccessService::STATE_PAUSED, $service->state('main'));
        self::assertSame(['state' => 'paused'], array_intersect_key($service->editorConfiguration('main'), ['state' => true]));

        [$service] = $this->access([], new AiConfigurationException('Not configured.', 1771002001));
        self::assertSame(EditorAiAccessService::STATE_NOT_CONFIGURED, $service->state('main'));
    }

    /** @return array{0:EditorAiAccessService} */
    private function access(array $tsConfig, ?\Throwable $resolveFailure = null): array
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getTSConfig')->willReturn($tsConfig);
        $backendUser->method('check')->willReturnCallback(static fn (string $type, string $value): bool => $type === 'modules' && $value === 'web_a11y' && !($tsConfig['noModule'] ?? false));
        $users = $this->createMock(BackendUserService::class);
        $users->method('getBackendUser')->willReturn($backendUser);
        $featureAccess = $this->createMock(AiFeatureAccessServiceInterface::class);
        $featureAccess->method('isAvailable')->willReturn(true);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        if ($resolveFailure !== null) {
            $resolver->method('resolve')->willThrowException($resolveFailure);
        } else {
            $resolver->method('resolve')->willReturn(new AiProviderConfiguration('openai', 'sk-secret', 'gpt-5.4-mini', 'site', 'fp'));
        }
        $resolver->method('status')->willReturn(['provider' => 'openai', 'selectedModelId' => 'gpt-5.4-mini', 'selectedModelLabel' => 'GPT-5.4 mini', 'keyHint' => 'sk-…abcd']);
        $configurations = new InMemoryAiConfigurationRepository();

        return [new EditorAiAccessService($featureAccess, $resolver, new AiSiteSettingsService(new InMemoryAiSiteSettingRepository(), $configurations), $configurations, $users)];
    }
}
