<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Adapter\Official;

use Armature\McpAnalytics\Recorder;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Server\Session\SessionInterface;

final class InstrumentedReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
        private readonly InstrumentedRegistry $registry,
        private readonly Recorder $recorder,
        private readonly RequestContextStore $requestContextStore,
    ) {
    }

    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if (!$reference instanceof ToolReference) {
            return $this->inner->handle($reference, $arguments);
        }

        $session = ($arguments['_session'] ?? null) instanceof SessionInterface
            ? $arguments['_session']
            : null;
        $sessionId = null === $session ? null : $session->getId()->toRfc4122();
        $requestContext = $this->requestContextStore->current($sessionId);
        $clientInfo = self::clientInfo($session);
        $protocolVersion = self::header($requestContext['headers'] ?? [], 'Mcp-Protocol-Version');
        if (null !== $protocolVersion) {
            $clientInfo ??= [];
            $clientInfo['protocolVersion'] = $protocolVersion;
        }

        return $this->recorder->instrumentToolCall(
            name: $reference->tool->name,
            arguments: $arguments,
            handler: fn (mixed $cleanArguments): mixed => $this->inner->handle(
                $reference,
                \is_array($cleanArguments) ? $cleanArguments : [],
            ),
            telemetryMode: $this->registry->telemetryMode($reference),
            sessionId: $sessionId,
            // JSON-RPC ids are connection-local counters, not analytics
            // idempotency keys. Recorder mints a collision-safe UUID.
            requestId: null,
            headers: $requestContext['headers'] ?? null,
            attributes: $requestContext['attributes'] ?? [],
            clientInfo: $clientInfo,
            workflowRunId: null,
            capabilityRequest: $this->registry->isCapabilityRequest($reference),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function clientInfo(?SessionInterface $session): ?array
    {
        if (null === $session) {
            return null;
        }
        $raw = $session->get('client_info');
        $info = \is_array($raw) ? $raw : [];
        $capabilities = $session->get('client_capabilities');
        $protocolVersion = $session->get('protocol_version');
        if (\is_array($capabilities)) {
            $info['capabilities'] = $capabilities;
        }
        if (\is_string($protocolVersion)) {
            $info['protocolVersion'] = $protocolVersion;
        }

        return [] === $info ? null : $info;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (0 === \strcasecmp($key, $name) && '' !== \trim($value)) {
                return \trim($value);
            }
        }

        return null;
    }
}
