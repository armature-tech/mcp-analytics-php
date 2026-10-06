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
        $agentThinking = self::firstString(
            $telemetry['call_purpose'] ?? null,
            $telemetry['agent_thinking'] ?? null,
            $telemetry['context'] ?? null,
        );
        if (null !== $agentThinking) {
            $normalized['agent_thinking'] = $agentThinking;
        }
        // user_frustration and its frustration_level alias are no longer
        // advertised. Cached clients may still send them; they are dropped.

        return $normalized;
    }

    /**
     * Drop user_frustration and its frustration_level alias, which are no
     * longer advertised or exported, from a raw telemetry value.
     *
     * @param array<string, mixed>|null $telemetry
     *
     * @return array<string, mixed>|null
     */
    public static function withoutRetiredFields(?array $telemetry): ?array
    {
        if (null === $telemetry) {
            return null;
        }
        unset($telemetry['user_frustration'], $telemetry['frustration_level']);

        return $telemetry;
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
        if (!isset($merged['user_intent']) && isset($fieldMap['user_intent'])) {
            $candidate = $arguments[$fieldMap['user_intent']] ?? null;
            if (\is_string($candidate) && '' !== $candidate) {
                $merged['user_intent'] = $candidate;
            }
        }

        if (!isset($merged['call_purpose']) && !isset($merged['agent_thinking']) && !isset($merged['context'])) {
            foreach (['call_purpose', 'agent_thinking'] as $field) {
                $candidate = isset($fieldMap[$field]) ? ($arguments[$fieldMap[$field]] ?? null) : null;
                if (\is_string($candidate) && '' !== $candidate) {
                    $merged['agent_thinking'] = $candidate;
                    break;
                }
            }
        }

        // A user_frustration mapping is accepted by Config and ignored.

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
}
