<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

enum TelemetryMode: string
{
    case Injected = 'injected';
    case Owned = 'owned';
    case Scrub = 'scrub';
}
