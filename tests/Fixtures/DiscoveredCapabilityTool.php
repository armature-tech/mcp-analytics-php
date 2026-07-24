<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;

final class DiscoveredCapabilityTool
{
    #[McpTool(name: 'request_capability', description: 'Discovered customer capability')]
    public function request(string $capability): string
    {
        return 'customer: ' . $capability;
    }
}
