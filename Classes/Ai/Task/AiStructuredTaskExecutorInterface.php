<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;

/**
 * Provider-independent execution of one structured request. Implementations send exactly one request to the
 * configured provider and model (no fallback model, no retry with another provider), report usage to the
 * AiUsageMeter and return only a schema-valid answer.
 */
interface AiStructuredTaskExecutorInterface
{
    public function supports(string $provider): bool;

    /** @throws AiProviderException with the shared AQG provider error codes */
    public function execute(AiStructuredRequest $request, AiProviderConfiguration $configuration): AiStructuredResponse;
}
