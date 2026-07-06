<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Contract;

use Priebera\A11yQualityGate\Remediation\ImageFindingContext;

interface ImageReferenceWriterInterface
{
    /** @param array<string,mixed> $values */
    public function write(ImageFindingContext $context, array $values): void;
}
