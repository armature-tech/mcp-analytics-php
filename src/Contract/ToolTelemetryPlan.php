<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

use Armature\McpAnalytics\TelemetryMode;

final class ToolTelemetryPlan
{
    /**
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        public readonly TelemetryMode $mode,
        public readonly array $inputSchema,
        public readonly ?string $description,
    ) {
    }
}
