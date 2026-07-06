<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Contract;

use Priebera\A11yQualityGate\Remediation\ImageFindingContext;

interface ImageFindingVersionTokenServiceInterface
{
    public function create(ImageFindingContext $context): string;
    public function assertValid(string $token, ImageFindingContext $context): void;
}
