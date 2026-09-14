<?php

declare(strict_types=1);

namespace GuzzleHttp;

/**
 * Stand-in for `GuzzleHttp\Utils` from Guzzle 8, which has no `jsonEncode()` or `jsonDecode()`.
 * Load it only in a separate test process, before Guzzle's own `Utils` class is autoloaded.
 */
final class Utils
{
}
