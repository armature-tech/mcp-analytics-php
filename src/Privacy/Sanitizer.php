<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Privacy;

final class Sanitizer
{
    public const BINARY_REMOVED_PLACEHOLDER = '[binary removed]';
    public const BASE64_REMOVED_PLACEHOLDER = '[base64 removed]';
    public const REDACTION_FAILED_PLACEHOLDER = '[redaction failed]';
    public const SANITIZATION_BUDGET = 65_536;

    private const DATA_URI_MIN_CHARS = 64;
    private const BASE64_MIN_CHARS = 512;
    private const BASE64_PATTERN = '~^[A-Za-z0-9+/_-]+={0,2}$~';
    private const EMBEDDED_BASE64_PATTERN = '~[A-Za-z0-9+/_-]{512,}={0,2}~';

    public static function value(mixed $value): mixed
    {
        $remaining = self::SANITIZATION_BUDGET;

        return self::bounded($value, new \SplObjectStorage(), $remaining);
    }

    /**
     * @param callable(mixed): mixed|null $redact
     */
    public static function prepareForPreview(
        mixed $value,
        mixed $redact = null,
        bool $redactSecrets = true,
    ): mixed {
        $sanitized = self::value($value);
        $protected = $redactSecrets ? SecretRedactor::value($sanitized) : $sanitized;
        if (!\is_callable($redact)) {
            return $protected;
        }

        try {
            return $redact($protected);
        } catch (\Throwable) {
            return self::REDACTION_FAILED_PLACEHOLDER;
        }
    }

    private static function string(string $value): string
    {
        if (
            \strlen($value) >= self::DATA_URI_MIN_CHARS
            && \str_starts_with($value, 'data:')
            && \str_contains($value, ';base64,')
        ) {
            return self::BASE64_REMOVED_PLACEHOLDER;
        }
        if (\strlen($value) < self::BASE64_MIN_CHARS) {
            return $value;
        }
        if (1 === \preg_match(self::BASE64_PATTERN, $value)) {
            return self::BASE64_REMOVED_PLACEHOLDER;
        }

        return \preg_replace(
            self::EMBEDDED_BASE64_PATTERN,
            self::BASE64_REMOVED_PLACEHOLDER,
            $value,
        ) ?? self::REDACTION_FAILED_PLACEHOLDER;
    }

    private static function charge(int &$remaining, int $units): bool
    {
        if ($remaining < $units) {
            $remaining = 0;

            return false;
        }
        $remaining -= $units;

        return true;
    }

    /**
     * @param \SplObjectStorage<object, mixed> $seen
     */
    private static function bounded(
        mixed $value,
        \SplObjectStorage $seen,
        int &$remaining,
    ): mixed {
        if (\is_string($value)) {
            $bounded = \strlen($value) > $remaining ? \substr($value, 0, $remaining) : $value;
            $sanitized = self::string($bounded);
            if (\strlen($sanitized) <= $remaining) {
                $remaining -= \strlen($sanitized);

                return $sanitized;
            }
            $sliced = \substr($sanitized, 0, $remaining);
            $remaining = 0;

            return $sliced;
        }
        if (\is_array($value)) {
            $result = [];
            $list = \array_is_list($value);
            foreach ($value as $key => $entry) {
                $keyText = (string) $key;
                if (!self::charge($remaining, $list ? 2 : \strlen($keyText) + 2)) {
                    break;
                }
                if (
                    'data' === $key
                    && \is_string($entry)
                    && \in_array($value['type'] ?? null, ['image', 'audio'], true)
                ) {
                    $result[$key] = self::bounded(self::BINARY_REMOVED_PLACEHOLDER, $seen, $remaining);
                } elseif ('blob' === $key && \is_string($entry)) {
                    $result[$key] = self::bounded(self::BINARY_REMOVED_PLACEHOLDER, $seen, $remaining);
                } else {
                    $result[$key] = self::bounded($entry, $seen, $remaining);
                }
                if (0 === $remaining) {
                    break;
                }
            }

            return $result;
        }
        if (!\is_object($value)) {
            return $value;
        }
        if ($seen->contains($value)) {
            return self::bounded(SecretRedactor::CIRCULAR_PLACEHOLDER, $seen, $remaining);
        }

        $seen->attach($value);
        try {
            $entries = $value instanceof \JsonSerializable ? $value->jsonSerialize() : \get_object_vars($value);
            if (!\is_array($entries)) {
                return $entries;
            }

            return self::bounded($entries, $seen, $remaining);
        } catch (\Throwable) {
            return self::REDACTION_FAILED_PLACEHOLDER;
        } finally {
            $seen->detach($value);
        }
    }
}
