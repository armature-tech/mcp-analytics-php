<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

interface EmitterInterface
{
    /**
     * @param array{schema_version: 1, events: list<array<string, mixed>>, sdk?: array{language: string, version: string}} $batch
     */
    public function emit(array $batch): void;
}
