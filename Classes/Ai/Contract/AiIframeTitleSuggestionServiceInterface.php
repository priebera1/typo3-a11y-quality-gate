<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionResult;

interface AiIframeTitleSuggestionServiceInterface
{
    public function suggest(int $findingId): AiIframeTitleSuggestionResult;
}
