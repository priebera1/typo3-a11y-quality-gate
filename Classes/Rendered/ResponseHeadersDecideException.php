<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Psr\Http\Message\ResponseInterface;

/**
 * @internal Thrown from the on_headers callback when the headers alone decide the outcome (a redirect, an HTTP error
 * status, a non-HTML type): the transfer ends before any body byte, and the fetcher continues with these headers.
 */
final class ResponseHeadersDecideException extends \RuntimeException
{
    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct('The response headers decide the rendered check.', 1771003104);
    }
}
