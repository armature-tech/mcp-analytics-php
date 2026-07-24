<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

final class Json
{
    public const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return \json_encode($value, self::FLAGS);
    }

    public static function preview(mixed $value): string
    {
        try {
            return self::encode($value);
        } catch (\Throwable) {
            return '[unserialisable]';
        }
    }
}
