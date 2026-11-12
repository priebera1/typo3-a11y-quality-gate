<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Pricing;

/** A central price catalogue that could not be fetched or trusted; $reason is a safe code for the diagnostics. */
final class AiPriceCatalogueException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
