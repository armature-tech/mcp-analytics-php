<?php

declare(strict_types=1);

namespace Armature\McpAnalytics;

use Armature\McpAnalytics\Delivery\EmitterInterface;
use Armature\McpAnalytics\Delivery\SchedulerInterface;
use Psr\Log\LoggerInterface;

/**
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
 *   agent_thinking?: string,
 *   user_frustration?: string
 * }
 */
final class Config
{
    public const DEFAULT_ENDPOINT_URL = 'https://app.armature.tech/api/mcp-analytics/ingest';

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
        public readonly ?bool $requestCapability = null,
        public readonly ?LoggerInterface $logger = null,
    ) {
        if ($this->timeoutMs < 1) {
            throw new \InvalidArgumentException('timeoutMs must be at least 1.');
        }
        if (DeliveryMode::Deferred === $this->delivery && null === $this->scheduler) {
            throw new \InvalidArgumentException('Deferred delivery requires a scheduler.');
        }
        foreach ($this->telemetryFieldMap as $field => $argument) {
            if (!\in_array($field, ['user_intent', 'agent_thinking', 'user_frustration'], true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown telemetry field map key "%s".', $field));
            }
            if ('' === \trim($argument)) {
                throw new \InvalidArgumentException(\sprintf('Telemetry field map value for "%s" must not be empty.', $field));
            }
        }
    }

    public static function fromEnvironment(): self
    {
        $apiKey = self::environment('ANALYTICS_INGEST_API_KEY');
        $endpoint = self::environment('ANALYTICS_INGEST_URL');

        return new self(
            endpointUrl: $endpoint ?? self::DEFAULT_ENDPOINT_URL,
            apiKey: $apiKey,
        );
    }

    public function hasDeliveryPath(): bool
    {
        return $this->enabled && (null !== $this->emitter || (null !== $this->apiKey && '' !== \trim($this->apiKey)));
    }

    public function requestCapabilityEnabled(): bool
    {
        return false !== $this->requestCapability && $this->hasDeliveryPath();
    }

    public function requestCapabilityExplicit(): bool
    {
        return true === $this->requestCapability;
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
