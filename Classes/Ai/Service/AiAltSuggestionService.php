<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiAltSuggestionServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;

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
    ) {}

    /** @return array{result:AiAltSuggestionResult,version:string} */
    public function suggest(int $findingId): array
    {
        $context = $this->resolver->resolve($findingId);
        $this->permissionService->assertCanModify($context, [], 'ai-alt-suggestion');

        if (!$this->featureAccessService->isAvailable($context->siteIdentifier)) {
            throw new AiConfigurationException('AI alt-text suggestions require a valid PRO or Agency licence.', 1771002401);
        }

        $this->rateLimiter->assertAllowed($context->siteIdentifier);
        $configuration = $this->configurationResolver->resolve($context->siteIdentifier);
        $request = $this->requestBuilder->build(
            $context,
            $this->imagePayloadBuilder->build($context),
        );

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

            $result = $provider->suggestAltText($request, $configuration);

            return [
                'result' => $this->suggestionValidator->validate($result, $request),
                'version' => $this->versionTokenService->create($context),
            ];
        }

        throw new AiConfigurationException('No AI provider is available.', 1771002402);
    }
}
