<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;

interface AiAltSuggestionServiceInterface
{
    /** @return array{result:AiAltSuggestionResult,version:string} */
    public function suggest(int $findingId): array;
}
