<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

interface EmitterInterface
{
    /**
     * @param array{schema_version: 1, events: list<array<string, mixed>>} $batch
     */
    public function emit(array $batch): void;
}
