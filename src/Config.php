<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Armature\McpAnalytics\Delivery\EmitterInterface;
use Armature\McpAnalytics\Delivery\SchedulerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * TelemetryFieldMap: user_frustration is accepted for compatibility and
 * ignored; that field is no longer exported.
 *
 * @phpstan-type ActorContext array{
 *   headers?: array<string, string|list<string>>,
 *   attributes?: array<string, mixed>,
 *   session_id?: string|null,
 *   tool_name?: string|null,
 *   telemetry?: array<string, mixed>|null
 * }
 * @phpstan-type RedactableEvent array<string, mixed>
 * @phpstan-type TelemetryFieldMap array{
 *   user_intent?: string,
 *   call_purpose?: string,
 *   agent_thinking?: string,
 *   user_frustration?: string
 * }
 */
final class Config
{
    public const DEFAULT_ENDPOINT_URL = 'https://app.armature.tech/api/mcp-analytics/ingest';

    /**
     * Accepted values of the deprecated descriptionLengthLogLevel setting.
     */
    public const DESCRIPTION_LENGTH_LOG_LEVELS = ['none', LogLevel::DEBUG, LogLevel::INFO, LogLevel::WARNING];

    /**
     * @param string|callable(ActorContext): string|null            $actorId
     * @param string|callable(ActorContext): ?string|null           $actorIdentifier
     * @param callable(mixed): mixed|null                           $redact
     * @param callable(RedactableEvent): ?RedactableEvent|null      $redactEvent
     * @param callable(\Throwable, array<string, mixed>): void|null $onError
     * @param array<string, string>                                 $telemetryFieldMap
     */
    public function __construct(
        public readonly string $endpointUrl = self::DEFAULT_ENDPOINT_URL,
        public readonly ?string $apiKey = null,
        public readonly bool $enabled = true,
        public readonly DeliveryMode $delivery = DeliveryMode::Await,
        public readonly int $timeoutMs = 5_000,
        public readonly ?EmitterInterface $emitter = null,
        public readonly mixed $onError = null,
        public readonly mixed $actorId = null,
        public readonly mixed $actorIdentifier = null,
        public readonly bool $captureTelemetry = true,
        public readonly bool $redactSecrets = true,
        public readonly mixed $redact = null,
        public readonly mixed $redactEvent = null,
        public readonly ?SchedulerInterface $scheduler = null,
        public readonly array $telemetryFieldMap = [],
        /**
         * @deprecated Use sendFeedback. Still accepted; sendFeedback wins
         *             when both are set.
         */
        public readonly ?bool $requestCapability = null,
        public readonly ?LoggerInterface $logger = null,
        /**
         * @deprecated The SDK no longer appends text to tool descriptions,
         *             so there is no length notice to log. Still validated
         *             ('none', 'debug', 'info' or 'warning') and otherwise
         *             ignored.
         */
        public readonly string $descriptionLengthLogLevel = LogLevel::WARNING,
        /**
         * The SDK-owned send_feedback tool. Null (default) means on whenever
         * a delivery path is configured; false turns it off; true turns it on
         * and makes a customer tool named send_feedback a configuration
         * error.
         */
        public readonly ?bool $sendFeedback = null,
    ) {
        if ($this->timeoutMs < 1) {
            throw new \InvalidArgumentException('timeoutMs must be at least 1.');
        }
        if (!\in_array($this->descriptionLengthLogLevel, self::DESCRIPTION_LENGTH_LOG_LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('descriptionLengthLogLevel must be one of %s.', \implode(', ', self::DESCRIPTION_LENGTH_LOG_LEVELS)));
        }
        if (DeliveryMode::Deferred === $this->delivery && null === $this->scheduler) {
            throw new \InvalidArgumentException('Deferred delivery requires a scheduler.');
        }
        foreach ($this->telemetryFieldMap as $field => $argument) {
            if (!\in_array($field, ['user_intent', 'call_purpose', 'agent_thinking', 'user_frustration'], true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown telemetry field map key "%s".', $field));
            }
            if ('' === \trim($argument)) {
                throw new \InvalidArgumentException(\sprintf('Telemetry field map value for "%s" must not be empty.', $field));
            }
        }
    }

    /**
     * Read ANALYTICS_INGEST_API_KEY and ANALYTICS_INGEST_URL. Pass
     * sendFeedback: false to turn off the send_feedback tool.
     */
    public static function fromEnvironment(?bool $sendFeedback = null): self
    {
        $apiKey = self::environment('ANALYTICS_INGEST_API_KEY');
        $endpoint = self::environment('ANALYTICS_INGEST_URL');

        return new self(
            endpointUrl: $endpoint ?? self::DEFAULT_ENDPOINT_URL,
            apiKey: $apiKey,
            sendFeedback: $sendFeedback,
        );
    }

    public function hasDeliveryPath(): bool
    {
        return $this->enabled && (null !== $this->emitter || (null !== $this->apiKey && '' !== \trim($this->apiKey)));
    }

    /**
     * The resolved send_feedback setting: sendFeedback when set, otherwise
     * the deprecated requestCapability alias, otherwise null (default).
     */
    public function sendFeedbackSetting(): ?bool
    {
        return $this->sendFeedback ?? $this->requestCapability;
    }

    /**
     * send_feedback is on by default whenever a delivery path is configured,
     * and off when set to false.
     */
    public function sendFeedbackEnabled(): bool
    {
        return false !== $this->sendFeedbackSetting() && $this->hasDeliveryPath();
    }

    /**
     * True when send_feedback was explicitly set to true, not merely on by
     * default.
     */
    public function sendFeedbackExplicit(): bool
    {
        return true === $this->sendFeedbackSetting();
    }

    /**
     * @deprecated Use sendFeedbackEnabled()
     */
    public function requestCapabilityEnabled(): bool
    {
        return $this->sendFeedbackEnabled();
    }

    /**
     * @deprecated Use sendFeedbackExplicit()
     */
    public function requestCapabilityExplicit(): bool
    {
        return $this->sendFeedbackExplicit();
    }

    private static function environment(string $name): ?string
    {
        $value = \getenv($name);
        if (false === $value) {
            return null;
        }

        $value = \trim($value);

        return '' === $value ? null : $value;
    }
}
