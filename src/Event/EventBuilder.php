<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Event;

use Armature\McpAnalytics\Contract\Json;
use Armature\McpAnalytics\Contract\Telemetry;
use Armature\McpAnalytics\Privacy\InvalidRedactionResult;
use Armature\McpAnalytics\Privacy\Sanitizer;
use Armature\McpAnalytics\Privacy\SecretRedactor;
use Armature\McpAnalytics\Privacy\Utf8;

final class EventBuilder
{
    public const SCHEMA_VERSION = 1;
    public const MAX_SOURCE_BYTES = 32 * 1024;
    public const MAX_PREVIEW_BYTES = 8 * 1024;
    public const MAX_CAPABILITIES_BYTES = 4 * 1024;

    /**
     * @return array<string, mixed>
     */
    public static function actorIdentity(
        string $actorId,
        string $identifier,
        string $startedAt,
    ): array {
        return self::base(
            eventId: Identity::eventId($actorId, $identifier, 'actor_identity'),
            kind: 'actor_identity',
            actorId: $actorId,
            sessionId: null,
            startedAt: $startedAt,
            finishedAt: $startedAt,
            durationMs: 0,
            ok: true,
            error: null,
            metadata: ['identifier' => $identifier],
        );
    }

    /**
     * @param array<string, mixed>                       $input
     * @param callable(mixed): mixed|null                $redact
     * @param callable(array<string, mixed>): mixed|null $redactEvent
     *
     * @return array<string, mixed>|null
     */
    public static function toolCall(
        array $input,
        mixed $redact = null,
        mixed $redactEvent = null,
        bool $redactSecrets = true,
    ): ?array {
        $candidate = self::prepareToolCandidate($input, $redact, $redactSecrets);
        if (\is_callable($redactEvent)) {
            try {
                $redacted = $redactEvent($candidate);
                if (null === $redacted) {
                    return null;
                }
                if (!\is_array($redacted)) {
                    throw new InvalidRedactionResult();
                }
                $candidate = $redacted;
            } catch (\Throwable) {
                $candidate = [
                    ...$candidate,
                    'input' => Sanitizer::REDACTION_FAILED_PLACEHOLDER,
                    'output' => Sanitizer::REDACTION_FAILED_PLACEHOLDER,
                    'error_message' => Sanitizer::REDACTION_FAILED_PLACEHOLDER,
                    'telemetry' => null,
                ];
            }
        }

        return self::assembleToolCandidate($candidate, $input);
    }

    /**
     * @param array<string, mixed>|null          $clientInfo
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, mixed>
     */
    public static function sessionInit(
        string $actorId,
        string $sessionId,
        string $startedAt,
        ?array $clientInfo = null,
        array $headers = [],
        ?string $authClientId = null,
        ?string $workflowRunId = null,
    ): array {
        $capabilities = $clientInfo['capabilities'] ?? null;
        if (!\is_array($capabilities) || \strlen(Json::preview($capabilities)) > self::MAX_CAPABILITIES_BYTES) {
            $capabilities = null;
        }
        $clientName = self::trimmed($clientInfo['name'] ?? null)
            ?? self::trimmed($authClientId)
            ?? self::trimmed(SessionIds::header($headers, 'x-mcp-client'));

        return [
            ...self::workflowStamp($workflowRunId),
            ...self::base(
                eventId: Identity::eventId($actorId, $sessionId, 'session_init'),
                kind: 'session_init',
                actorId: $actorId,
                sessionId: $sessionId,
                startedAt: $startedAt,
                finishedAt: $startedAt,
                durationMs: 0,
                ok: true,
                error: null,
                metadata: [
                    'client_name' => $clientName,
                    'client_version' => self::trimmed($clientInfo['version'] ?? null),
                    'protocol_version' => self::trimmed($clientInfo['protocolVersion'] ?? null),
                    'capabilities' => $capabilities,
                    'user_agent' => SessionIds::header($headers, 'user-agent'),
                ],
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array{schema_version: 1, events: list<array<string, mixed>>}
     */
    public static function batch(array $events): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'events' => $events];
    }

    /**
     * @param array<string, mixed>        $input
     * @param callable(mixed): mixed|null $redact
     *
     * @return array<string, mixed>
     */
    private static function prepareToolCandidate(
        array $input,
        mixed $redact,
        bool $redactSecrets,
    ): array {
        $telemetry = isset($input['telemetry']) && \is_array($input['telemetry'])
            ? Telemetry::normalize($input['telemetry'])
            : null;
        if (null !== $telemetry) {
            foreach (['user_intent', 'agent_thinking'] as $field) {
                if (isset($telemetry[$field]) && \is_string($telemetry[$field])) {
                    $telemetry[$field] = Sanitizer::prepareForPreview(
                        $telemetry[$field],
                        redactSecrets: $redactSecrets,
                    );
                }
            }
            if (\is_callable($redact)) {
                try {
                    $redactedTelemetry = $redact($telemetry);
                    $telemetry = \is_array($redactedTelemetry)
                        ? Telemetry::normalize($redactedTelemetry)
                        : null;
                } catch (\Throwable) {
                    $telemetry = null;
                }
            }
        }

        $candidate = [
            'kind' => 'tool_call',
            'tool_name' => (string) ($input['tool_name'] ?? ''),
            'status' => 'error' === ($input['status'] ?? null) ? 'error' : 'ok',
            'duration_ms' => \max(0, (int) ($input['duration_ms'] ?? 0)),
            'input' => Sanitizer::prepareForPreview(
                $input['input'] ?? null,
                $redact,
                $redactSecrets,
            ),
        ];
        if (isset($input['session_id']) && '' !== (string) $input['session_id']) {
            $candidate['session_id'] = (string) $input['session_id'];
        }
        if (\array_key_exists('output', $input)) {
            $candidate['output'] = Sanitizer::prepareForPreview(
                $input['output'],
                $redact,
                $redactSecrets,
            );
        }
        if (isset($input['error_message']) && \is_string($input['error_message'])) {
            $errorMessage = $redactSecrets
                ? SecretRedactor::string($input['error_message'])
                : $input['error_message'];
            if (\is_callable($redact)) {
                try {
                    $redactedError = $redact($errorMessage);
                    $errorMessage = \is_string($redactedError) ? $redactedError : Json::preview($redactedError);
                } catch (\Throwable) {
                    $errorMessage = Sanitizer::REDACTION_FAILED_PLACEHOLDER;
                }
            }
            $candidate['error_message'] = $errorMessage;
        }
        if (null !== $telemetry) {
            $candidate['telemetry'] = $telemetry;
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private static function assembleToolCandidate(array $candidate, array $input): array
    {
        $toolName = \is_string($candidate['tool_name'] ?? null) ? $candidate['tool_name'] : '';
        $candidateInput = $candidate['input'] ?? null;
        $inputPreview = Utf8::truncate(Json::preview($candidateInput), self::MAX_PREVIEW_BYTES);
        $source = Utf8::truncate(
            'MCP tool call: ' . $toolName . "\n\nInput:\n" . Json::preview($candidateInput),
            self::MAX_SOURCE_BYTES,
        );
        $resultPreview = \array_key_exists('output', $candidate)
            ? Utf8::truncate(Json::preview($candidate['output']), self::MAX_PREVIEW_BYTES)
            : null;
        $telemetry = isset($candidate['telemetry']) && \is_array($candidate['telemetry'])
            ? Telemetry::normalize($candidate['telemetry'])
            : null;
        $status = 'error' === ($candidate['status'] ?? null) ? 'error' : 'ok';
        $actorId = (string) ($input['actor_id'] ?? '');
        $requestId = (string) ($input['request_id'] ?? '');
        $metadata = [
            'tool_name' => $toolName,
            'user_intent' => $telemetry['user_intent'] ?? null,
            'agent_thinking' => $telemetry['agent_thinking'] ?? null,
            'user_frustration' => $telemetry['user_frustration'] ?? null,
            'intent' => $telemetry['user_intent'] ?? null,
            'context' => $telemetry['agent_thinking'] ?? null,
            'frustration_level' => $telemetry['user_frustration'] ?? null,
            'input_preview' => $inputPreview['value'],
        ];
        if (true === ($input['capability_request'] ?? false)) {
            $metadata['capability_request'] = true;
        }

        return [
            ...self::workflowStamp(
                isset($input['workflow_run_id']) && \is_string($input['workflow_run_id'])
                    ? $input['workflow_run_id']
                    : null,
            ),
            ...self::base(
                eventId: Identity::eventId($actorId, $requestId, 'tool_call'),
                kind: 'tool_call',
                actorId: $actorId,
                sessionId: isset($candidate['session_id']) ? (string) $candidate['session_id'] : null,
                startedAt: (string) ($input['started_at'] ?? ''),
                finishedAt: (string) ($input['finished_at'] ?? ''),
                durationMs: \max(0, (int) ($candidate['duration_ms'] ?? 0)),
                ok: 'ok' === $status,
                error: isset($candidate['error_message']) ? (string) $candidate['error_message'] : null,
                metadata: $metadata,
                scriptSource: $source['value'],
                scriptSourceTruncated: $source['truncated'],
                resultPreview: $resultPreview['value'] ?? null,
                resultTruncated: $resultPreview['truncated'] ?? false,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function base(
        string $eventId,
        string $kind,
        string $actorId,
        ?string $sessionId,
        string $startedAt,
        string $finishedAt,
        int $durationMs,
        bool $ok,
        ?string $error,
        array $metadata,
        ?string $scriptSource = null,
        bool $scriptSourceTruncated = false,
        ?string $resultPreview = null,
        bool $resultTruncated = false,
    ): array {
        return [
            'event_id' => $eventId,
            'kind' => $kind,
            'actor_id' => $actorId,
            'session_id_hint' => $sessionId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'ok' => $ok,
            'error' => $error,
            'metadata' => $metadata,
            'script_source' => $scriptSource,
            'script_source_truncated' => $scriptSourceTruncated,
            'result_preview' => $resultPreview,
            'result_truncated' => $resultTruncated,
            'calls' => [],
            'logs' => [],
            'search_calls' => [],
        ];
    }

    /**
     * @return array{is_workflow: true, workflow_run_id: string}|array{}
     */
    private static function workflowStamp(?string $workflowRunId): array
    {
        return null === $workflowRunId || '' === $workflowRunId
            ? []
            : ['is_workflow' => true, 'workflow_run_id' => $workflowRunId];
    }

    private static function trimmed(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = \trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
