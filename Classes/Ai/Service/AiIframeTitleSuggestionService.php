<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiIframeTitleSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

final class AiIframeTitleSuggestionService implements AiIframeTitleSuggestionServiceInterface
{
    /** @param iterable<AiProviderInterface> $providers */
    public function __construct(
        private readonly AiIframeTitleSuggestionContextResolver $contextResolver,
        private readonly AiFeatureAccessServiceInterface $featureAccessService,
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiConfigurationRepositoryInterface $configurationRepository,
        private readonly AiIframeTitleSuggestionRequestBuilder $requestBuilder,
        private readonly AiIframeTitleSuggestionResponseValidator $responseValidator,
        private readonly AiRateLimiter $rateLimiter,
        private readonly iterable $providers,
    ) {}

    public function suggest(int $findingId): AiIframeTitleSuggestionResult
    {
        $context = $this->contextResolver->resolve($findingId);

        if (!$this->featureAccessService->isAvailable($context->siteIdentifier)) {
            throw new AiConfigurationException('AI iframe-title suggestions require a valid PRO or Agency licence.', 1771002940);
        }

        if (!$this->configurationRepository->isLinkTextSuggestionsEnabled($context->siteIdentifier)) {
            throw new AiConfigurationException('AI iframe-title suggestions are disabled for this site.', 1771002941);
        }

        $this->rateLimiter->assertAllowed($context->siteIdentifier);
        $configuration = $this->configurationResolver->resolve($context->siteIdentifier);
        $request = $this->requestBuilder->build($context);

        foreach ($this->providers as $provider) {
            if (!$provider->supports($configuration->provider)) {
                continue;
            }

            return $this->responseValidator->validate(
                $provider->suggestIframeTitle($request, $configuration),
                $request,
            );
        }

        throw new AiConfigurationException('No AI provider is available.', 1771002942);
    }
}
