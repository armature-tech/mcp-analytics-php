<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;

final class DiscoveredTool
{
    #[McpTool(name: 'discovered_echo', description: 'Discovered echo')]
    public function echo(string $text): string
    {
        return 'discovered: ' . $text;
    }
}
