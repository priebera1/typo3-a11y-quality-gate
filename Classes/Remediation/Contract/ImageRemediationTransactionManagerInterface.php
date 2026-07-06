<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Contract;

interface ImageRemediationTransactionManagerInterface
{
    public function transactional(callable $operation): mixed;
}
