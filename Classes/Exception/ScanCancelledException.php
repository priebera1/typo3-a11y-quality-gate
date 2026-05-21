<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Exception;

final class ScanCancelledException extends \RuntimeException
{
    public function __construct(string $message = 'Scan was cancelled by the user.')
    {
        parent::__construct($message, 1700000002);
    }
}
