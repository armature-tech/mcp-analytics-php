<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Composer\InstalledVersions;

final class Version
{
    public static function current(): string
    {
        if (\class_exists(InstalledVersions::class)) {
            return InstalledVersions::getPrettyVersion('armature/mcp-analytics') ?? 'dev';
        }

        return 'dev';
    }
}
