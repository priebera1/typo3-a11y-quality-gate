<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Contract;

interface FileReferenceSchemaServiceInterface
{
    public function hasDecorativeColumn(): bool;
    public function alternativeStorageLimit(): int;
}
