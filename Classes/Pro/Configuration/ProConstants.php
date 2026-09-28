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
    /** Re-check interval after an outage or rate limit, so the API is not asked on every request. */
    public const CACHE_TTL_TRANSIENT = 120;
    /** Offline grace: how long the last valid answer outlives an unreachable API (never past its end). */
    public const CACHE_TTL_GRACE = 172800;

    public const TOKEN_REFRESH_MARGIN = 300;

    /**
     * Free Remote Preview entitlement status is fetched while the backend module renders, so it
     * uses a shorter timeout than the interactive submit/status calls and a short bounded cache.
     * Only the Free display payload is ever cached — never a paid entitlement, never a token.
     */
    public const FREE_ENTITLEMENT_REQUEST_TIMEOUT = 3.0;
    public const FREE_ENTITLEMENT_CACHE_TTL = 60;
    public const FREE_ENTITLEMENT_ERROR_CACHE_TTL = 15;

    public const CACHE_IDENTIFIER = 'a11y_quality_gate_pro';

    private function __construct()
    {
    }
}
