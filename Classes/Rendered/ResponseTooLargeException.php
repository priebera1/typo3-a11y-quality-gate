<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

/** @internal Thrown from the on_headers callback when the declared body size already exceeds the rendered-check limit. */
final class ResponseTooLargeException extends \RuntimeException {}
