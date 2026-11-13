<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * A scan has more stored results than one request can check against the user's permissions; it is refused rather than
 * checked partially (RemoteResultAuthorizationService).
 */
final class RemoteResultsTooLargeException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The scan has more stored results than can be checked against the user\'s permissions in one request.', 1793500101);
    }
}
