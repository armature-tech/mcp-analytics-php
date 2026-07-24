<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Privacy;

final class Utf8
{
    /**
     * @return array{value: string, truncated: bool}
     */
    public static function truncate(string $value, int $maxBytes): array
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('maxBytes must not be negative.');
        }
        if (\strlen($value) <= $maxBytes) {
            return ['value' => $value, 'truncated' => false];
        }

        $slice = \substr($value, 0, $maxBytes);
        while ('' !== $slice && 1 !== \preg_match('//u', $slice)) {
            $slice = \substr($slice, 0, -1);
        }

        return ['value' => $slice, 'truncated' => true];
    }
}
