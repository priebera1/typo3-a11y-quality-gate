<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;

/**
 * Runs one structured AI task for a site through whichever provider the site uses: licence gate, site privacy
 * settings, rate limit, the site's verified configuration, one provider request and a usage row. Callers resolve
 * the site and their context server-side and check the user's permission on the target before calling; the result
 * is only a suggestion and is never written anywhere by this runner.
 */
final class AiStructuredTaskRunner
{
    /** @param iterable<AiStructuredTaskExecutorInterface> $executors */
    public function __construct(
        private readonly AiFeatureAccessServiceInterface $featureAccessService,
        private readonly AiSiteSettingsService $siteSettingsService,
        private readonly AiRateLimiter $rateLimiter,
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiUsageRecorder $usageRecorder,
        private readonly iterable $executors,
    ) {}

    /**
     * @param string $reference opaque usage grouping (an "Ask AQG" conversation ref), never content
     * @param (callable():void)|null $afterAdmission the caller's limit check, run after the attempt's usage row is
     *        admitted and before the provider request (AiUsageRecorder::track()); a throw refuses the request
     */
    public function run(string $siteIdentifier, AiStructuredRequest $request, string $reference = '', ?callable $afterAdmission = null): AiStructuredResponse
    {
        if (!$this->featureAccessService->isAvailable($siteIdentifier)) {
            throw new AiConfigurationException('AI features require a valid PRO or Agency licence.', 1771002404);
        }
        $siteSettings = $this->siteSettingsService->get($siteIdentifier);
        if ($request->image !== null && !$siteSettings->allowImageInput) {
            throw new AiConfigurationException('This site does not allow sending images to AI providers.', 1771002403);
        }
        if ($request->includesPageTitle && !$siteSettings->allowPageTitle) {
            throw new AiConfigurationException('This site does not allow sending page titles to AI providers.', 1771002405);
        }

        $this->rateLimiter->assertAllowed($siteIdentifier);
        $configuration = $this->configurationResolver->resolve($siteIdentifier);
        foreach ($this->executors as $executor) {
            if ($executor->supports($configuration->provider)) {
                return $this->usageRecorder->track(
                    $siteIdentifier,
                    $request->task,
                    $configuration,
                    static fn (): AiStructuredResponse => $executor->execute($request, $configuration),
                    AiCostBound::forStructuredRequest($request, $configuration->provider),
                    $reference,
                    $afterAdmission,
                );
            }
        }

        throw new AiConfigurationException('No AI provider is available.', 1771002402);
    }
}
