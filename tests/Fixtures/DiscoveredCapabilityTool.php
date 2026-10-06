<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;

final class DiscoveredCapabilityTool
{
    #[McpTool(name: 'send_feedback', description: 'Discovered customer feedback tool')]
    public function request(string $capability): string
    {
        return 'customer: ' . $capability;
    }
}
