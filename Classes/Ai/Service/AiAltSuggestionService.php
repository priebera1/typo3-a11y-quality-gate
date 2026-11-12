<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiAltSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;

final class AiAltSuggestionService implements AiAltSuggestionServiceInterface
{
    /** @param iterable<AiProviderInterface> $providers */
    public function __construct(
        private readonly ImageFindingContextResolverInterface $resolver,
        private readonly ImageRemediationPermissionServiceInterface $permissionService,
        private readonly AiFeatureAccessServiceInterface $featureAccessService,
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiImagePayloadBuilder $imagePayloadBuilder,
        private readonly AiAltSuggestionRequestBuilder $requestBuilder,
        private readonly AiAltSuggestionValidator $suggestionValidator,
        private readonly AiRateLimiter $rateLimiter,
        private readonly ImageFindingVersionTokenServiceInterface $versionTokenService,
        private readonly iterable $providers,
        private readonly ?AiUsageRecorder $usageRecorder = null,
        private readonly ?AiSiteSettingsService $siteSettingsService = null,
        private readonly ?EditorAiAccessService $editorAiAccessService = null,
    ) {}

    /** @return array{result:AiAltSuggestionResult,version:string} */
    public function suggest(int $findingId): array
    {
        $context = $this->resolver->resolve($findingId);
        $this->permissionService->assertCanModify($context, [], 'ai-alt-suggestion');
        // User TSconfig options.a11y_quality_gate.ai.images = 0: no AI for images (Documentation/Configuration/Permissions.rst).
        if ($this->editorAiAccessService !== null && !$this->editorAiAccessService->userAllows(EditorAiAccessService::FLAG_IMAGES)) {
            throw new ImageRemediationPermissionException('The user group may not use AI for images.', 1793804405);
        }

        if (!$this->featureAccessService->isAvailable($context->siteIdentifier)) {
            throw new AiConfigurationException('AI alt-text suggestions require a valid PRO or Agency licence.', 1771002401);
        }

        $siteSettings = $this->siteSettingsService?->get($context->siteIdentifier);
        if ($siteSettings !== null && !$siteSettings->allowImageInput) {
            throw new AiConfigurationException('This site does not allow sending images to AI providers.', 1771002403);
        }

        $this->rateLimiter->assertAllowed($context->siteIdentifier);
        $configuration = $this->configurationResolver->resolve($context->siteIdentifier);
        $request = $this->requestBuilder->build(
            $context,
            $this->imagePayloadBuilder->build($context),
        );
        if ($siteSettings !== null && !$siteSettings->allowPageTitle) {
            $request = $request->withoutPageTitle();
        }

        if ($request->isLinked === true && trim((string)$request->linkPurpose) === '') {
            return [
                'result' => new AiAltSuggestionResult(
                    status: AiAltSuggestionResult::STATUS_NEEDS_REVIEW,
                    suggestion: '',
                    provider: $configuration->provider,
                    model: $configuration->model,
                    promptVersion: AiPromptDefinition::AI_PROMPT_VERSION,
                ),
                'version' => $this->versionTokenService->create($context),
            ];
        }

        foreach ($this->providers as $provider) {
            if (!$provider->supports($configuration->provider)) {
                continue;
            }

            $result = $this->usageRecorder !== null
                ? $this->usageRecorder->track(
                    $context->siteIdentifier,
                    AiTask::ALT_TEXT,
                    $configuration,
                    static fn () => $provider->suggestAltText($request, $configuration),
                    AiCostBound::forLegacyTask(AiTask::ALT_TEXT, $configuration->provider, (string)json_encode($request->contextPayload()), 1),
                )
                : $provider->suggestAltText($request, $configuration);

            return [
                'result' => $this->suggestionValidator->validate($result, $request),
                'version' => $this->versionTokenService->create($context),
            ];
        }

        throw new AiConfigurationException('No AI provider is available.', 1771002402);
    }
}
