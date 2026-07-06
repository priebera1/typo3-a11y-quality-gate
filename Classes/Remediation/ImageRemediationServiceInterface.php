<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

interface ImageRemediationServiceInterface
{
    public function resolve(int $findingId): ImageFindingContext;
    public function markDecorative(int $findingId, string $expectedVersion): ImageFindingContext;
    public function markInformative(int $findingId, string $expectedVersion): ImageFindingContext;
    public function applyAlt(int $findingId, string $altText, string $expectedVersion): ImageFindingContext;
}
