<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Contract;

use Armature\McpAnalytics\TelemetryMode;

final class Telemetry
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{arguments: array<string, mixed>, telemetry: array<string, mixed>|null}
     */
    public static function extract(array $arguments, TelemetryMode $mode = TelemetryMode::Injected): array
    {
        if (TelemetryMode::Owned === $mode) {
            return ['arguments' => $arguments, 'telemetry' => null];
        }

        if (!\array_key_exists('telemetry', $arguments)) {
            return ['arguments' => $arguments, 'telemetry' => null];
        }

        $raw = $arguments['telemetry'];
        unset($arguments['telemetry']);

        if (TelemetryMode::Scrub === $mode || !\is_array($raw)) {
            return ['arguments' => $arguments, 'telemetry' => null];
        }

        return ['arguments' => $arguments, 'telemetry' => self::normalize($raw)];
    }

    /**
     * @param array<string, mixed>|null $telemetry
     *
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $telemetry): ?array
    {
        if (null === $telemetry) {
            return null;
        }

        $normalized = [];
        $userIntent = self::firstString($telemetry['user_intent'] ?? null, $telemetry['intent'] ?? null);
        if (null !== $userIntent) {
            $normalized['user_intent'] = $userIntent;
        }
        $agentThinking = self::firstString($telemetry['agent_thinking'] ?? null, $telemetry['context'] ?? null);
        if (null !== $agentThinking) {
            $normalized['agent_thinking'] = $agentThinking;
        }
        $frustration = self::firstFrustration(
            $telemetry['user_frustration'] ?? null,
            $telemetry['frustration_level'] ?? null,
        );
        if (null !== $frustration) {
            $normalized['user_frustration'] = $frustration;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $telemetry
     * @param array<string, mixed>      $arguments
     * @param array<string, string>     $fieldMap
     *
     * @return array<string, mixed>|null
     */
    public static function applyFieldMap(?array $telemetry, array $arguments, array $fieldMap): ?array
    {
        if ([] === $fieldMap) {
            return $telemetry;
        }

        $merged = $telemetry ?? [];
        foreach (['user_intent', 'agent_thinking'] as $field) {
            if (isset($merged[$field]) || !isset($fieldMap[$field])) {
                continue;
            }
            $candidate = $arguments[$fieldMap[$field]] ?? null;
            if (\is_string($candidate) && '' !== $candidate) {
                $merged[$field] = $candidate;
            }
        }

        if (!isset($merged['user_frustration']) && isset($fieldMap['user_frustration'])) {
            $candidate = self::firstFrustration($arguments[$fieldMap['user_frustration']] ?? null);
            if (null !== $candidate) {
                $merged['user_frustration'] = $candidate;
            }
        }

        return [] === $merged ? $telemetry : $merged;
    }

    private static function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (\is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function firstFrustration(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (\is_string($value) && \in_array($value, ['low', 'medium', 'high'], true)) {
                return $value;
            }
        }

        return null;
    }
}
