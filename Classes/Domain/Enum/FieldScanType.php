<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Enum;

enum FieldScanType: string
{
    case Rte = 'rte';
    case File = 'file';
}
