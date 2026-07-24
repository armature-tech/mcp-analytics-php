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
            ?? $this->mainContext;
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
