<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiLinkTextSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Ai\Exception\AiLinkTextSuggestionException;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;

final class AiLinkTextSuggestionService implements AiLinkTextSuggestionServiceInterface
{
    /** @param iterable<AiProviderInterface> $providers */
    public function __construct(
        private readonly AiLinkTextSuggestionContextResolver $contextResolver,
        private readonly AiFeatureAccessServiceInterface $featureAccessService,
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiConfigurationRepositoryInterface $configurationRepository,
        private readonly AiLinkTextSuggestionRequestBuilder $requestBuilder,
        private readonly AiLinkTextSuggestionResponseValidator $responseValidator,
        private readonly AiRateLimiter $rateLimiter,
        private readonly iterable $providers,
        private readonly ?AiUsageRecorder $usageRecorder = null,
        private readonly ?AiSiteSettingsService $siteSettingsService = null,
        // N-08: the user's own AI switch (options.a11y_quality_gate.ai.editor). Nullable for DI containers built before.
        private readonly ?EditorAiAccessService $editorAiAccessService = null,
    ) {}

    public function suggest(int $findingId): AiLinkTextSuggestionResult
    {
        $context = $this->contextResolver->resolve($findingId);

        if (!$this->featureAccessService->isAvailable($context->siteIdentifier)) {
            throw new AiConfigurationException('AI link-text suggestions require a valid PRO or Agency licence.', 1771002840);
        }

        if (!$this->configurationRepository->isLinkTextSuggestionsEnabled($context->siteIdentifier)) {
            throw new AiConfigurationException('AI link-text suggestions are disabled for this site.', 1771002841);
        }

        // N-08 (final customer acceptance): a billable request that sends content to the provider needs the user's
        // ai.editor permission too, not only the site's "Text suggestions" switch.
        if ($this->editorAiAccessService !== null && !$this->editorAiAccessService->userAllows(EditorAiAccessService::FLAG_EDITOR)) {
            throw new AiConfigurationException('AI link-text suggestions are not enabled for this user.', 1771002842);
        }

        $this->rateLimiter->assertAllowed($context->siteIdentifier);
        $configuration = $this->configurationResolver->resolve($context->siteIdentifier);
        $request = $this->requestBuilder->build($context);
        if ($this->siteSettingsService !== null && !$this->siteSettingsService->get($context->siteIdentifier)->allowPageTitle) {
            $request = $request->withoutPageTitle();
        }

        foreach ($this->providers as $provider) {
            if (!$provider->supports($configuration->provider)) {
                continue;
            }

            $result = $this->usageRecorder !== null
                ? $this->usageRecorder->track(
                    $context->siteIdentifier,
                    AiTask::LINK_TEXT,
                    $configuration,
                    static fn () => $provider->suggestLinkText($request, $configuration),
                    AiCostBound::forLegacyTask(AiTask::LINK_TEXT, $configuration->provider, (string)json_encode($request->contextPayload())),
                )
                : $provider->suggestLinkText($request, $configuration);

            return $this->responseValidator->validate($result, $request);
        }

        throw new AiConfigurationException('No AI provider is available.', 1771002842);
    }
}
