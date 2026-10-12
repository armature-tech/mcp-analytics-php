<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Adapter\Official;

use Armature\McpAnalytics\Recorder;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Implementation;
use Mcp\Server\Session\SessionInterface;

final class InstrumentedReferenceHandler implements ReferenceHandlerInterface
{
    /**
     * mcp/sdk 0.8+ stores the per-request metadata of a 2026-07-28 request
     * under this session key. Named as a string so 0.7 still type-checks.
     */
    private const REQUEST_META = 'Mcp\\Server\\Stateless\\RequestMeta';

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
        $requestMeta = self::requestMeta($session);
        // A 2026-07-28 request runs in a throwaway session with a fresh id per
        // call. Send no session id there, so ingest groups the calls itself.
        $sessionId = null === $session || null !== $requestMeta ? null : $session->getId()->toRfc4122();
        $requestContext = $this->requestContextStore->current($sessionId);
        $clientInfo = self::clientInfo($session, $requestMeta);
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
    private static function clientInfo(?SessionInterface $session, ?object $requestMeta): ?array
    {
        if (null === $session) {
            return null;
        }
        $raw = $session->get('client_info');
        $info = \is_array($raw) ? $raw : [];
        if (null !== $requestMeta) {
            // The 2026-07-28 revision sends clientInfo on each request, not
            // once at initialize. It is optional there.
            $requestClient = \get_object_vars($requestMeta)['clientInfo'] ?? null;
            if ($requestClient instanceof Implementation) {
                $info = $requestClient->jsonSerialize();
            }
        }
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

    private static function requestMeta(?SessionInterface $session): ?object
    {
        $meta = $session?->get(self::REQUEST_META);

        return \is_object($meta) && \is_a($meta, self::REQUEST_META) ? $meta : null;
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
