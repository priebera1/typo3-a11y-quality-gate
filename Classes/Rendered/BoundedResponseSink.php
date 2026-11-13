<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * The response body sink of one rendered-page request (SOL-FINAL-02). The HTTP client writes the received body into it
 * chunk by chunk, after Content-Encoding decoding: cURL inflates gzip/deflate/brotli incrementally and hands at most
 * CURL_MAX_WRITE_SIZE (16 KiB) per write, so the count is of decoded bytes and a compression bomb is cut off as it expands.
 *
 * A write that would take the body beyond $maxBytes is refused (0 bytes written) and marks the sink exceeded. cURL treats
 * a short write as a write error and aborts the transfer at once (Guzzle 7 and 8); Guzzle's stream handler (used only
 * without the cURL extension) stops copying. Nothing beyond $maxBytes is ever stored: the buffer is a memory stream that
 * never spills to a temporary file, at most $maxBytes long.
 */
final class BoundedResponseSink implements StreamInterface
{
    private readonly Stream $buffer;
    private int $bytes = 0;
    private bool $exceeded = false;

    public function __construct(private readonly int $maxBytes)
    {
        $resource = fopen('php://memory', 'w+b');
        if ($resource === false) {
            throw new \RuntimeException('The response buffer could not be opened.', 1771003101);
        }
        $this->buffer = new Stream($resource);
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    /** Decoded body bytes accepted so far (never more than the limit). */
    public function bytes(): int
    {
        return $this->bytes;
    }

    public function write(string $string): int
    {
        $length = strlen($string);
        if ($this->exceeded || $this->bytes + $length > $this->maxBytes) {
            $this->exceeded = true;

            return 0;
        }
        $written = $this->buffer->write($string);
        $this->bytes += $written;

        return $written;
    }

    public function __toString(): string
    {
        return (string)$this->buffer;
    }

    public function close(): void
    {
        $this->buffer->close();
    }

    public function detach()
    {
        return $this->buffer->detach();
    }

    public function getSize(): ?int
    {
        return $this->buffer->getSize();
    }

    public function tell(): int
    {
        return $this->buffer->tell();
    }

    public function eof(): bool
    {
        return $this->buffer->eof();
    }

    public function isSeekable(): bool
    {
        return $this->buffer->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->buffer->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->buffer->rewind();
    }

    public function isWritable(): bool
    {
        return !$this->exceeded && $this->buffer->isWritable();
    }

    public function isReadable(): bool
    {
        return $this->buffer->isReadable();
    }

    public function read(int $length): string
    {
        return $this->buffer->read($length);
    }

    public function getContents(): string
    {
        return $this->buffer->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->buffer->getMetadata($key);
    }
}
