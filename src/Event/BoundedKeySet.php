<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Event;

final class BoundedKeySet
{
    /** @var array<string, true> */
    private array $keys = [];

    public function __construct(private readonly int $maxEntries)
    {
        if ($this->maxEntries < 1) {
            throw new \InvalidArgumentException('maxEntries must be at least 1.');
        }
    }

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function add(string $key): void
    {
        if (isset($this->keys[$key])) {
            return;
        }
        if (\count($this->keys) >= $this->maxEntries) {
            $oldest = \array_key_first($this->keys);
            unset($this->keys[$oldest]);
        }
        $this->keys[$key] = true;
    }

    public function count(): int
    {
        return \count($this->keys);
    }
}
