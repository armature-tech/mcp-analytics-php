<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

final class NullEmitter implements EmitterInterface
{
    public function emit(array $batch): void
    {
    }
}
