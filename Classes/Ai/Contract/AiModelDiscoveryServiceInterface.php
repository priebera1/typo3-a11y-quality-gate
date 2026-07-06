<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

interface AiModelDiscoveryServiceInterface
{
    /**
     * @return array{supported:list<array{id:string,label:string}>,unsupported:list<string>,selectedModelId:string,discoveredAt:int}
     */
    public function discover(string $siteIdentifier): array;

    public function ensureFresh(string $siteIdentifier): void;
}
