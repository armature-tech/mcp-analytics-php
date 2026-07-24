<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Armature\McpAnalytics\Contract\Json;
use Armature\McpAnalytics\Contract\Telemetry;
use Armature\McpAnalytics\Delivery\EmitterInterface;
use Armature\McpAnalytics\Delivery\NullEmitter;
use Armature\McpAnalytics\Delivery\PrivacyQueue;
use Armature\McpAnalytics\Delivery\SymfonyIngestEmitter;
use Armature\McpAnalytics\Event\BoundedKeySet;
use Armature\McpAnalytics\Event\EventBuilder;
use Armature\McpAnalytics\Event\Identity;
use Armature\McpAnalytics\Event\SessionIds;

final class Recorder
{
    private const MAX_ACTOR_IDENTIFIER_BYTES = 8 * 1024;
    private const MAX_IDENTITY_KEYS = 10_000;
    private const MAX_SESSION_INIT_KEYS = 10_000;

    private readonly PrivacyQueue $queue;
    private readonly BoundedKeySet $sessionInitKeys;

    /** @var array<string, string> */
    private array $actorIdentifiers = [];

    /** @var array<string, TelemetryMode> */
    private array $toolModes = [];

    public function __construct(
        private readonly Config $config = new Config(),
        ?EmitterInterface $emitter = null,
    ) {
        $resolvedEmitter = $emitter
            ?? $this->config->emitter
            ?? (null !== $this->config->apiKey && '' !== \trim($this->config->apiKey)
                ? new SymfonyIngestEmitter(
                    $this->config->endpointUrl,
                    $this->config->apiKey,
                    $this->config->timeoutMs,
                )
                : new NullEmitter());
        $this->queue = new PrivacyQueue($this->config, $resolvedEmitter);
        $this->sessionInitKeys = new BoundedKeySet(self::MAX_SESSION_INIT_KEYS);
    }

    public function setToolTelemetryMode(string $toolName, TelemetryMode $mode): void
    {
        $this->toolModes[$toolName] = $mode;
    }

    /**
     * @param array<string, string|list<string>>|null $headers
     * @param array<string, mixed>                    $attributes
     * @param array<string, mixed>|null               $clientInfo
     */
    public function recordSessionInit(
        ?string $sessionId = null,
        ?array $headers = null,
        array $attributes = [],
        ?array $clientInfo = null,
        string|\DateTimeInterface|int|float|null $startedAt = null,
        ?string $workflowRunId = null,
    ): void {
        if (!$this->config->enabled) {
            return;
        }
        $resolvedSessionId = $this->resolveSessionId($sessionId, $headers);
        if (null === $resolvedSessionId) {
            return;
        }
        $started = self::normalizeDate($startedAt);
        $workflow = $workflowRunId ?? SessionIds::workflowRunId($headers ?? []);

        $this->queue->enqueue(function () use (
            $resolvedSessionId,
            $headers,
            $attributes,
            $clientInfo,
            $started,
            $workflow,
        ): ?array {
            $context = $this->analyticsContext(
                $headers ?? [],
                $attributes,
                $resolvedSessionId,
                null,
                null,
            );
            $events = [];
            $identity = $this->identityEvent($context, $started);
            if (null !== $identity) {
                $events[] = $identity;
            }
            $key = $context['actor_id'] . ':' . $resolvedSessionId;
            if (!$this->sessionInitKeys->has($key)) {
                $this->sessionInitKeys->add($key);
                $events[] = EventBuilder::sessionInit(
                    $context['actor_id'],
                    $resolvedSessionId,
                    $started,
                    $clientInfo ?? SessionIds::parseClientInfo($resolvedSessionId),
                    $headers ?? [],
                    self::attributeString($attributes, ['client_id', 'clientId']),
                    $workflow,
                );
            }

            return [] === $events ? null : $events;
        });
    }

    /**
     * @param array<string, mixed>|null               $telemetry
     * @param array<string, string|list<string>>|null $headers
     * @param array<string, mixed>                    $attributes
     * @param array<string, mixed>|null               $clientInfo
     */
    public function recordToolCall(
        string $name,
        mixed $arguments = null,
        ?array $telemetry = null,
        string $status = 'ok',
        mixed $result = null,
        mixed $error = null,
        ?string $sessionId = null,
        ?string $requestId = null,
        string|\DateTimeInterface|int|float|null $startedAt = null,
        ?int $durationMs = null,
        ?array $headers = null,
        array $attributes = [],
        ?array $clientInfo = null,
        ?string $workflowRunId = null,
        bool $capabilityRequest = false,
        bool $resultProvided = true,
        ?TelemetryMode $telemetryMode = null,
    ): void {
        if (!$this->config->enabled) {
            return;
        }

        $mode = $telemetryMode ?? $this->toolModes[$name] ?? TelemetryMode::Injected;
        $capturedTelemetry = TelemetryMode::Owned === $mode ? null : $telemetry;
        $effectiveTelemetry = $this->config->captureTelemetry
            ? Telemetry::applyFieldMap(
                $capturedTelemetry,
                \is_array($arguments) ? $arguments : [],
                $this->config->telemetryFieldMap,
            )
            : null;
        $finishedMs = self::milliseconds();
        $duration = \max(0, $durationMs ?? 0);
        $finished = self::dateFromMilliseconds($finishedMs);
        $started = self::normalizeDate($startedAt, $finishedMs - $duration);
        $resolvedSessionId = $this->resolveSessionId($sessionId, $headers);
        $normalizedRequestId = SessionIds::requestId($requestId, $resolvedSessionId);
        $workflow = $workflowRunId ?? SessionIds::workflowRunId($headers ?? []);
        $errorMessage = self::errorMessage($error);

        $this->queue->enqueue(function () use (
            $name,
            $arguments,
            $effectiveTelemetry,
            $status,
            $result,
            $errorMessage,
            $resolvedSessionId,
            $normalizedRequestId,
            $started,
            $finished,
            $duration,
            $headers,
            $attributes,
            $clientInfo,
            $workflow,
            $capabilityRequest,
            $resultProvided,
        ): ?array {
            $context = $this->analyticsContext(
                $headers ?? [],
                $attributes,
                $resolvedSessionId,
                $name,
                $effectiveTelemetry,
            );
            $eventInput = [
                'tool_name' => $name,
                'telemetry' => $effectiveTelemetry,
                'input' => $arguments,
                'status' => 'error' === $status ? 'error' : 'ok',
                'duration_ms' => $duration,
                'error_message' => $errorMessage,
                'actor_id' => $context['actor_id'],
                'session_id' => $resolvedSessionId,
                'request_id' => $normalizedRequestId,
                'started_at' => $started,
                'finished_at' => $finished,
                'workflow_run_id' => $workflow,
                'capability_request' => $capabilityRequest,
            ];
            if ($resultProvided) {
                $eventInput['output'] = $result;
            }
            $toolEvent = EventBuilder::toolCall(
                $eventInput,
                $this->config->redact,
                $this->config->redactEvent,
                $this->config->redactSecrets,
            );
            $events = [];
            $identity = $this->identityEvent($context, $started);
            if (null !== $identity) {
                $events[] = $identity;
            }
            if (null !== $resolvedSessionId) {
                $key = $context['actor_id'] . ':' . $resolvedSessionId;
                if (!$this->sessionInitKeys->has($key)) {
                    $this->sessionInitKeys->add($key);
                    $events[] = EventBuilder::sessionInit(
                        $context['actor_id'],
                        $resolvedSessionId,
                        $started,
                        $clientInfo ?? SessionIds::parseClientInfo($resolvedSessionId),
                        $headers ?? [],
                        self::attributeString($attributes, ['client_id', 'clientId']),
                        $workflow,
                    );
                }
            }
            if (null !== $toolEvent) {
                $events[] = $toolEvent;
            }

            return [] === $events ? null : $events;
        });
    }

    /**
     * @param callable(mixed): mixed                  $handler
     * @param array<string, string|list<string>>|null $headers
     * @param array<string, mixed>                    $attributes
     * @param array<string, mixed>|null               $clientInfo
     */
    public function instrumentToolCall(
        string $name,
        mixed $arguments,
        callable $handler,
        TelemetryMode $telemetryMode = TelemetryMode::Injected,
        ?string $sessionId = null,
        ?string $requestId = null,
        ?array $headers = null,
        array $attributes = [],
        ?array $clientInfo = null,
        ?string $workflowRunId = null,
        bool $capabilityRequest = false,
    ): mixed {
        if (\is_array($arguments)) {
            $extracted = Telemetry::extract($arguments, $telemetryMode);
            $recordArguments = $extracted['arguments'];
            unset($recordArguments['_session'], $recordArguments['_request']);
        } else {
            $extracted = ['arguments' => $arguments, 'telemetry' => null];
            $recordArguments = $arguments;
        }
        $startedMs = self::milliseconds();
        $startedAt = self::dateFromMilliseconds($startedMs);

        try {
            $result = $handler($extracted['arguments']);
        } catch (\Throwable $error) {
            $this->recordToolCall(
                name: $name,
                arguments: $recordArguments,
                telemetry: $extracted['telemetry'],
                status: 'error',
                error: $error,
                sessionId: $sessionId,
                requestId: $requestId,
                startedAt: $startedAt,
                durationMs: self::milliseconds() - $startedMs,
                headers: $headers,
                attributes: $attributes,
                clientInfo: $clientInfo,
                workflowRunId: $workflowRunId,
                capabilityRequest: $capabilityRequest,
                resultProvided: false,
                telemetryMode: $telemetryMode,
            );

            throw $error;
        }

        $resultError = self::toolResultError($result);
        $this->recordToolCall(
            name: $name,
            arguments: $recordArguments,
            telemetry: $extracted['telemetry'],
            status: null === $resultError ? 'ok' : 'error',
            result: $result,
            error: $resultError,
            sessionId: $sessionId,
            requestId: $requestId,
            startedAt: $startedAt,
            durationMs: self::milliseconds() - $startedMs,
            headers: $headers,
            attributes: $attributes,
            clientInfo: $clientInfo,
            workflowRunId: $workflowRunId,
            capabilityRequest: $capabilityRequest,
            telemetryMode: $telemetryMode,
        );

        return $result;
    }

    public function flush(): void
    {
        $this->queue->flush();
    }

    public function close(): void
    {
        $this->queue->close();
    }

    public function dropped(): int
    {
        return $this->queue->dropped();
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed>               $attributes
     * @param array<string, mixed>|null          $telemetry
     *
     * @return array{actor_id: string, actor_identifier: string|null}
     */
    private function analyticsContext(
        array $headers,
        array $attributes,
        ?string $sessionId,
        ?string $toolName,
        ?array $telemetry,
    ): array {
        $callbackInput = [
            'headers' => $headers,
            'attributes' => $attributes,
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'telemetry' => $telemetry,
        ];
        $identifier = self::configuredString($this->config->actorIdentifier, $callbackInput);
        if (null !== $identifier && \strlen($identifier) > self::MAX_ACTOR_IDENTIFIER_BYTES) {
            $identifier = null;
        }
        $seed = $identifier
            ?? self::configuredString($this->config->actorId, $callbackInput)
            ?? self::attributeString(
                $attributes,
                ['principal_id', 'principalId', 'subject', 'sub', 'token', 'client_id', 'clientId', 'api_key', 'apiKey'],
            )
            ?? SessionIds::header($headers, 'authorization')
            ?? 'anonymous';

        return [
            'actor_id' => Identity::actorId($seed),
            'actor_identifier' => $identifier,
        ];
    }

    /**
     * @param array{actor_id: string, actor_identifier: string|null} $context
     *
     * @return array<string, mixed>|null
     */
    private function identityEvent(array $context, string $startedAt): ?array
    {
        $identifier = $context['actor_identifier'];
        if (null === $identifier || ($this->actorIdentifiers[$context['actor_id']] ?? null) === $identifier) {
            return null;
        }
        $this->actorIdentifiers[$context['actor_id']] = $identifier;
        if (\count($this->actorIdentifiers) > self::MAX_IDENTITY_KEYS) {
            $oldest = \array_key_first($this->actorIdentifiers);
            unset($this->actorIdentifiers[$oldest]);
        }

        return EventBuilder::actorIdentity($context['actor_id'], $identifier, $startedAt);
    }

    /**
     * @param array<string, string|list<string>>|null $headers
     */
    private function resolveSessionId(?string $sessionId, ?array $headers): ?string
    {
        $explicit = \trim($sessionId ?? '');
        if ('' !== $explicit) {
            return $explicit;
        }
        if (null === $headers) {
            return SessionIds::processScoped();
        }
        $header = \trim(SessionIds::header($headers, 'mcp-session-id') ?? '');

        return '' === $header ? null : $header;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function configuredString(mixed $configured, array $input): ?string
    {
        try {
            $value = \is_callable($configured) ? $configured($input) : $configured;
        } catch (\Throwable) {
            return null;
        }
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string>         $keys
     */
    private static function attributeString(array $attributes, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $attributes[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private static function errorMessage(mixed $error): ?string
    {
        if (null === $error) {
            return null;
        }
        if ($error instanceof \Throwable) {
            return $error->getMessage();
        }
        if (\is_string($error)) {
            return $error;
        }
        if (\is_scalar($error)) {
            return (string) $error;
        }

        return Json::preview($error);
    }

    private static function toolResultError(mixed $result): ?string
    {
        $shape = \is_array($result) ? $result : (\is_object($result) ? \get_object_vars($result) : null);
        if (!\is_array($shape) || true !== ($shape['isError'] ?? null)) {
            return null;
        }
        $content = $shape['content'] ?? null;
        if (\is_array($content)) {
            foreach ($content as $item) {
                $itemShape = \is_array($item) ? $item : (\is_object($item) ? \get_object_vars($item) : null);
                if (
                    \is_array($itemShape)
                    && 'text' === ($itemShape['type'] ?? null)
                    && \is_string($itemShape['text'] ?? null)
                    && '' !== \trim($itemShape['text'])
                ) {
                    return $itemShape['text'];
                }
            }
        }

        return 'tool returned isError';
    }

    private static function milliseconds(): int
    {
        return (int) \floor(\microtime(true) * 1_000);
    }

    private static function dateFromMilliseconds(int $milliseconds): string
    {
        $seconds = \intdiv($milliseconds, 1_000);
        $millis = $milliseconds % 1_000;

        return \gmdate('Y-m-d\TH:i:s', $seconds) . '.' . \str_pad((string) $millis, 3, '0', STR_PAD_LEFT) . 'Z';
    }

    private static function normalizeDate(
        string|\DateTimeInterface|int|float|null $value,
        ?int $fallbackMilliseconds = null,
    ): string {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        }
        if (\is_string($value) && '' !== $value) {
            try {
                return (new \DateTimeImmutable($value))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.v\Z');
            } catch (\Throwable) {
                // Fall through to a safe local timestamp.
            }
        }
        if ((\is_int($value) || \is_float($value)) && $value > 1_000_000_000_000) {
            return self::dateFromMilliseconds((int) $value);
        }

        return self::dateFromMilliseconds($fallbackMilliseconds ?? self::milliseconds());
    }
}
