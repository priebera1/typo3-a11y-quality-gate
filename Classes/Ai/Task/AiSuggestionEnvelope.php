<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

/**
 * A generated suggestion as it leaves AQG: always in state "suggested", never "applied". It carries its provenance
 * (provider, model, prompt version) and the fingerprint of the target it was generated for. Applying it is a separate,
 * permission-checked human action that must first confirm the fingerprint still matches.
 */
final readonly class AiSuggestionEnvelope
{
    public const STATE_SUGGESTED = 'suggested';

    public string $state;

    public function __construct(
        public string $task,
        public string $value,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public string $targetFingerprint,
        public int $createdAt,
    ) {
        if (!AiTask::isKnown($task) || $targetFingerprint === '') {
            throw new \InvalidArgumentException('A suggestion needs a known task and a target fingerprint.');
        }
        $this->state = self::STATE_SUGGESTED;
    }

    public function isStaleFor(array $currentTargetParts): bool
    {
        return !AiTargetFingerprint::matches($this->targetFingerprint, $currentTargetParts);
    }

    /** @return array{state:string,task:string,value:string,provider:string,model:string,promptVersion:string,targetFingerprint:string,createdAt:int} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'task' => $this->task,
            'value' => $this->value,
            'provider' => $this->provider,
            'model' => $this->model,
            'promptVersion' => $this->promptVersion,
            'targetFingerprint' => $this->targetFingerprint,
            'createdAt' => $this->createdAt,
        ];
    }
}
