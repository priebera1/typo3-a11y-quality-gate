<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionContextResolver;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionResponseValidator;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionService;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionContextResolver;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionResponseValidator;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionService;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;

/**
 * N-08 (final customer acceptance): an editor in a "no AI" group (options.a11y_quality_gate.ai.editor = 0) could still
 * request billable link-text and iframe-title suggestions from Page Detail, sending content to the provider. Both
 * services now refuse before the rate limiter, the configuration and any provider are touched.
 */
final class AiTextSuggestionUserPermissionTest extends TestCase
{
    #[Test]
    public function linkTextSuggestionsAreRefusedWithoutTheUsersAiPermission(): void
    {
        $context = $this->context(\Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionContext::class);
        $resolver = $this->createMock(AiLinkTextSuggestionContextResolver::class);
        $resolver->method('resolve')->willReturn($context);
        [$rateLimiter, $configurationResolver, $provider] = $this->untouchables();

        $service = new AiLinkTextSuggestionService($resolver, $this->licensed(), $configurationResolver, $this->siteSwitchOn(),
            $this->createMock(AiLinkTextSuggestionRequestBuilder::class), $this->createMock(AiLinkTextSuggestionResponseValidator::class),
            $rateLimiter, [$provider], null, null, $this->userWithoutAi());

        $this->expectExceptionObject(new AiConfigurationException('AI link-text suggestions are not enabled for this user.', 1771002842));
        $service->suggest(7);
    }

    #[Test]
    public function iframeTitleSuggestionsAreRefusedWithoutTheUsersAiPermission(): void
    {
        $context = $this->context(\Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionContext::class);
        $resolver = $this->createMock(AiIframeTitleSuggestionContextResolver::class);
        $resolver->method('resolve')->willReturn($context);
        [$rateLimiter, $configurationResolver, $provider] = $this->untouchables();

        $service = new AiIframeTitleSuggestionService($resolver, $this->licensed(), $configurationResolver, $this->siteSwitchOn(),
            $this->createMock(AiIframeTitleSuggestionRequestBuilder::class), $this->createMock(AiIframeTitleSuggestionResponseValidator::class),
            $rateLimiter, [$provider], null, null, $this->userWithoutAi());

        $this->expectExceptionObject(new AiConfigurationException('AI iframe-title suggestions are not enabled for this user.', 1771002942));
        $service->suggest(7);
    }

    private function context(string $class): object
    {
        $context = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        \Closure::bind(static function () use ($context): void {
            $context->siteIdentifier = 'main';
        }, null, $class)();

        return $context;
    }

    /** @return array{0:AiRateLimiter,1:AiConfigurationResolverInterface,2:AiProviderInterface} */
    private function untouchables(): array
    {
        $rateLimiter = $this->createMock(AiRateLimiter::class);
        $rateLimiter->expects(self::never())->method('assertAllowed');
        $configurationResolver = $this->createMock(AiConfigurationResolverInterface::class);
        $configurationResolver->expects(self::never())->method('resolve');
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->expects(self::never())->method(self::anything());

        return [$rateLimiter, $configurationResolver, $provider];
    }

    private function licensed(): AiFeatureAccessServiceInterface
    {
        $access = $this->createMock(AiFeatureAccessServiceInterface::class);
        $access->method('isAvailable')->willReturn(true);

        return $access;
    }

    private function siteSwitchOn(): AiConfigurationRepositoryInterface
    {
        $repository = $this->createMock(AiConfigurationRepositoryInterface::class);
        $repository->method('isLinkTextSuggestionsEnabled')->willReturn(true);

        return $repository;
    }

    private function userWithoutAi(): EditorAiAccessService
    {
        $access = $this->createMock(EditorAiAccessService::class);
        $access->method('userAllows')->willReturnCallback(static fn (string $flag): bool => $flag !== EditorAiAccessService::FLAG_EDITOR);

        return $access;
    }
}
