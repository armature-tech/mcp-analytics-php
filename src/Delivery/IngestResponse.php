<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

final class IngestResponse
{
    public static function safeCode(mixed $candidate, string $fallback): string
    {
        return \is_string($candidate)
            && 1 === \preg_match('/^[a-z0-9][a-z0-9_:-]{0,99}$/i', $candidate)
            ? $candidate
            : $fallback;
    }

    public static function rejection(string $body, int $eventCount, int $attempts = 1): ?DeliveryError
    {
        if ('' === \trim($body)) {
            return null;
        }
        try {
            $payload = \json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($payload)) {
            return null;
        }

        $rejected = $payload['rejected'] ?? null;
        if (\is_array($rejected) && [] !== $rejected) {
            $first = $rejected[0] ?? null;
            $candidate = \is_array($first)
                ? ($first['reason'] ?? $first['code'] ?? null)
                : null;

            return new DeliveryError(
                self::safeCode($candidate, 'ingest_rejected'),
                200,
                false,
                $attempts,
            );
        }
        if ($eventCount > 0 && 0 === ($payload['accepted'] ?? null)) {
            return new DeliveryError('ingest_accepted_zero', 200, false, $attempts);
        }

        return null;
    }

    public static function httpErrorCode(string $body, int $status): string
    {
        $fallback = 'ingest_http_' . $status;
        try {
            $payload = \json_decode(\substr($body, 0, 4_096), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $fallback;
        }
        if (!\is_array($payload)) {
            return $fallback;
        }
        $error = $payload['error'] ?? null;
        $candidate = \is_array($error) ? ($error['code'] ?? null) : ($payload['errorCode'] ?? null);

        return self::safeCode($candidate, $fallback);
    }
}
