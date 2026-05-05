<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Configuration;

final class ProConstants
{
    public const API_BASE_URL = 'https://api.priebera.sk';
    public const PRODUCT_SLUG = 'accessibility-quality-gate';
    public const REQUEST_TIMEOUT = 10.0;

    public const CACHE_TTL_VALID = 3600;
    public const CACHE_TTL_INVALID = 300;
    public const CACHE_TTL_TRIAL = 900;

    public const TOKEN_REFRESH_MARGIN = 300;

    public const CACHE_IDENTIFIER = 'a11y_quality_gate_pro';

    private function __construct()
    {
    }
}