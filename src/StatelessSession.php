<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Armature\McpAnalytics\Event\SessionIds;

final class StatelessSession
{
    /**
     * @param array{name?: mixed, version?: mixed}|null $clientInfo
     */
    public static function buildId(?array $clientInfo = null, ?string $sessionSeed = null): string
    {
        return SessionIds::buildStateless($clientInfo, $sessionSeed);
    }

    /**
     * @return array{name: string, version?: string}|null
     */
    public static function parseClientInfo(?string $sessionId): ?array
    {
        return SessionIds::parseClientInfo($sessionId);
    }
}
