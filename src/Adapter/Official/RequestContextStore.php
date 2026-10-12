<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Adapter\Official;

final class RequestContextStore
{
    /**
     * @var \WeakMap<object, array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }>
     */
    private \WeakMap $fiberContexts;

    /**
     * @var array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }|null
     */
    private ?array $mainContext = null;

    /**
     * The official SDK executes protocol handlers in a child Fiber created
     * after PSR-15 middleware enters. Session-keyed lookup carries the request
     * context across that Fiber boundary without merging concurrent sessions.
     *
     * @var array<string, array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }>
     */
    private array $sessionContexts = [];

    public function __construct()
    {
        $this->fiberContexts = new \WeakMap();
    }

    /**
     * @return array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }|null
     */
    public function current(?string $sessionId = null): ?array
    {
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            return $this->mainContext
                ?? (null === $sessionId ? null : ($this->sessionContexts[$sessionId] ?? null));
        }

        return $this->fiberContexts[$fiber]
            ?? (null === $sessionId ? null : ($this->sessionContexts[$sessionId] ?? null))
            ?? $this->mainContext
            ?? (null === $sessionId ? $this->onlyStatelessFiberContext() : null);
    }

    /**
     * A 2026-07-28 request has no session id to key on. When the middleware
     * itself runs in a Fiber, the SDK's child Fiber can still use the context
     * if it is the only request in flight and that request has no session id
     * either. Otherwise no context is safer than the wrong one.
     *
     * @return array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }|null
     */
    private function onlyStatelessFiberContext(): ?array
    {
        if (1 !== \count($this->fiberContexts)) {
            return null;
        }
        foreach ($this->fiberContexts as $context) {
            return null === self::sessionId($context['headers']) ? $context : null;
        }

        return null;
    }

    /**
     * @template T
     *
     * @param array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }                  $context
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(array $context, callable $operation): mixed
    {
        $sessionId = self::sessionId($context['headers']);
        $hadSessionContext = null !== $sessionId && isset($this->sessionContexts[$sessionId]);
        $previousSessionContext = null === $sessionId ? null : ($this->sessionContexts[$sessionId] ?? null);
        if (null !== $sessionId) {
            $this->sessionContexts[$sessionId] = $context;
        }
        $fiber = \Fiber::getCurrent();
        if (null === $fiber) {
            $previous = $this->mainContext;
            $this->mainContext = $context;
            try {
                return $operation();
            } finally {
                $this->mainContext = $previous;
                $this->restoreSessionContext($sessionId, $hadSessionContext, $previousSessionContext);
            }
        }

        $hadPrevious = isset($this->fiberContexts[$fiber]);
        $previous = $this->fiberContexts[$fiber] ?? null;
        $this->fiberContexts[$fiber] = $context;
        try {
            return $operation();
        } finally {
            if ($hadPrevious && null !== $previous) {
                $this->fiberContexts[$fiber] = $previous;
            } else {
                unset($this->fiberContexts[$fiber]);
            }
            $this->restoreSessionContext($sessionId, $hadSessionContext, $previousSessionContext);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function sessionId(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (0 === \strcasecmp($name, 'Mcp-Session-Id') && '' !== \trim($value)) {
                return \trim($value);
            }
        }

        return null;
    }

    /**
     * @param array{
     *   headers: array<string, string>,
     *   attributes: array<string, mixed>
     * }|null $previous
     */
    private function restoreSessionContext(
        ?string $sessionId,
        bool $hadPrevious,
        ?array $previous,
    ): void {
        if (null === $sessionId) {
            return;
        }
        if ($hadPrevious && null !== $previous) {
            $this->sessionContexts[$sessionId] = $previous;

            return;
        }
        unset($this->sessionContexts[$sessionId]);
    }
}
