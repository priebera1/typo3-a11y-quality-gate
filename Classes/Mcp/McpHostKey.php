<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use TYPO3\CMS\Core\Core\Environment;

/**
 * The host-local factor of the MCP connection secrets (clone safety N2).
 *
 * The connector and pairing secrets are encrypted with this key in addition to TYPO3's encryptionKey, so a copy of the
 * database together with the configuration — what a staging refresh copies — cannot decrypt them and never talks to the
 * Gateway as this installation. The key is 32 random bytes, either from the environment variable AQG_MCP_CONNECTOR_KEY
 * (base64; for containers and several web hosts) or from a file under TYPO3's var path, outside cache and public
 * directories, created on the first pairing (directory 0700, file 0600). Every web host and the connector host need the
 * same key. A lost key costs a new pairing, never data. A key is never regenerated while a connection encrypted with
 * another key is stored (McpConnectionStore reports such a connection as unusable on this host instead).
 *
 * The file is never kept inside the web root: in classic (non-Composer) mode TYPO3's var path is public/typo3temp/var,
 * which a web server without TYPO3's deny rules (nginx) would serve. There the key must come from the environment.
 */
final class McpHostKey
{
    public const ENVIRONMENT_VARIABLE = 'AQG_MCP_CONNECTOR_KEY';
    private const KEY_BYTES = 32;

    /** The key of this host, or null when none is configured or stored (or the configured one is invalid). */
    public function current(): ?string
    {
        $configured = getenv(self::ENVIRONMENT_VARIABLE);
        if (is_string($configured) && trim($configured) !== '') {
            // A configured key that does not decode is refused, never replaced by the file: fail closed.
            return self::decode($configured);
        }
        $path = $this->path();
        if (!$this->fileStoreAllowed() || !is_file($path)) {
            return null;
        }
        $stored = @file_get_contents($path);

        return is_string($stored) ? self::decode($stored) : null;
    }

    /** The key of this host; creates the key file when there is neither a configured nor a stored key. */
    public function ensure(): string
    {
        $key = $this->current();
        if ($key !== null) {
            return $key;
        }
        $configured = getenv(self::ENVIRONMENT_VARIABLE);
        if (is_string($configured) && trim($configured) !== '') {
            throw new McpHostKeyUnavailable(self::ENVIRONMENT_VARIABLE . ' is set but is not 32 bytes in base64.', 1793400201);
        }
        if (!$this->fileStoreAllowed()) {
            throw new McpHostKeyUnavailable('TYPO3\'s var path is inside the web root; set ' . self::ENVIRONMENT_VARIABLE . ' instead of a key file.', 1793400205);
        }
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new McpHostKeyUnavailable('The directory for the MCP connector key could not be created.', 1793400202);
        }
        $temporary = $directory . '/.mcp-connector.key.' . bin2hex(random_bytes(8));
        $umask = umask(0077);
        try {
            if (@file_put_contents($temporary, base64_encode(random_bytes(self::KEY_BYTES)) . "\n", LOCK_EX) === false) {
                throw new McpHostKeyUnavailable('The MCP connector key could not be written.', 1793400203);
            }
            @chmod($temporary, 0600);
            // Another process may have created the key meanwhile: the first one stays (link fails), this one is dropped.
            if (!@link($temporary, $path) && !is_file($path)) {
                @rename($temporary, $path);
            }
        } finally {
            umask($umask);
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        $key = $this->current();
        if ($key === null) {
            throw new McpHostKeyUnavailable('The MCP connector key could not be read back.', 1793400204);
        }

        return $key;
    }

    /** Whether this host's key comes from AQG_MCP_CONNECTOR_KEY (which only the operator can replace). */
    public function isConfigured(): bool
    {
        $configured = getenv(self::ENVIRONMENT_VARIABLE);

        return is_string($configured) && trim($configured) !== '';
    }

    /**
     * Deletes the key file, so the next pairing creates a new key (Sol AQG-MCP-07: a full copy of an installation also
     * copies this file). Only for a host without a stored connection; returns whether a file was removed.
     */
    public function discardStoredKey(): bool
    {
        $path = $this->path();
        if ($this->isConfigured() || !$this->fileStoreAllowed() || !is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /** A non-secret id of a key, stored with the connection to tell "wrong key" from "no key". */
    public static function idOf(string $key): string
    {
        return substr(hash('sha256', "aqg-mcp-host-key-id:v1\n" . $key), 0, 16);
    }

    /** A key file only outside the web root (Composer mode's var/; never classic mode's public/typo3temp/var). */
    public function fileStoreAllowed(): bool
    {
        $public = rtrim(Environment::getPublicPath(), '/') . '/';
        $var = rtrim(Environment::getVarPath(), '/') . '/';

        return !str_starts_with($var, $public);
    }

    public function path(): string
    {
        return rtrim(Environment::getVarPath(), '/') . '/a11y_quality_gate/mcp-connector.key';
    }

    private static function decode(string $encoded): ?string
    {
        $key = base64_decode(trim($encoded), true);

        return is_string($key) && strlen($key) === self::KEY_BYTES ? $key : null;
    }
}
