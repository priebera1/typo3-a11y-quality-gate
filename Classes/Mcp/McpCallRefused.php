<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * An agent call refused by a rule. `reason` is machine-readable for the agent and the audit trail; `message` is English
 * protocol text that names no other user or site.
 */
final class McpCallRefused extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly string $auditResult = 'denied',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
