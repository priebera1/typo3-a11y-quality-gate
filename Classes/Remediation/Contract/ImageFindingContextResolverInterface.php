<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Contract;

use Priebera\A11yQualityGate\Remediation\ImageFindingContext;

interface ImageFindingContextResolverInterface
{
    public function supportsIssueRow(array $issue): bool;
    public function resolve(int $findingId): ImageFindingContext;
}
