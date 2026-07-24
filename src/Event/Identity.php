<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Event;

final class Identity
{
    public static function actorId(string $actorSeed): string
    {
        return \hash('sha256', $actorSeed);
    }

    public static function eventId(string $actorId, string $requestId, string $kind): string
    {
        return \hash('sha256', $actorId . ' ' . $kind . ' ' . $requestId);
    }
}
