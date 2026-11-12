<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/** This host cannot hold an MCP connector key (McpHostKey): nothing is paired, nothing is sent to the Gateway. */
final class McpHostKeyUnavailable extends \RuntimeException
{
}
