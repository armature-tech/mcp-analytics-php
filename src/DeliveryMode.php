<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

enum DeliveryMode: string
{
    case Await = 'await';
    case Deferred = 'deferred';
}
