<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Delivery;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\DeliveryMode;
use Armature\McpAnalytics\Event\EventBuilder;

final class PrivacyQueue
{
    public const CAPACITY = 1_000;
    public const BATCH_SIZE = 20;

    /** @var \SplQueue<callable(): (list<array<string, mixed>>|null)> */
    private \SplQueue $pending;
    private bool $scheduled = false;
    private bool $draining = false;
    private bool $accepting = true;
    private bool $warnedOverflow = false;
    private int $dropped = 0;

    public function __construct(
        private readonly Config $config,
        private readonly EmitterInterface $emitter,
    ) {
        $this->pending = new \SplQueue();
    }

    /**
     * @param callable(): (list<array<string, mixed>>|null) $finalize
     */
    public function enqueue(callable $finalize): void
    {
        if (!$this->accepting || !$this->config->enabled) {
            return;
        }
        if ($this->pending->count() >= self::CAPACITY) {
            $this->pending->dequeue();
            ++$this->dropped;
            if (!$this->warnedOverflow) {
                $this->warnedOverflow = true;
                \trigger_error(
                    'Armature analytics privacy queue overflow; dropping oldest candidates.',
                    E_USER_WARNING,
                );
            }
        }
        $this->pending->enqueue($finalize);
        if (DeliveryMode::Await === $this->config->delivery) {
            $this->flush();

            return;
        }
        $this->schedule();
    }

    public function flush(): void
    {
        if ($this->draining) {
            return;
        }
        $this->scheduled = false;
        $this->draining = true;
        try {
            while (!$this->pending->isEmpty()) {
                $events = [];
                for ($index = 0; $index < self::BATCH_SIZE && !$this->pending->isEmpty(); ++$index) {
                    $finalize = $this->pending->dequeue();
                    try {
                        $finalized = $finalize();
                        if (null !== $finalized) {
                            foreach ($finalized as $event) {
                                $events[] = $event;
                            }
                        }
                    } catch (\Throwable $error) {
                        $this->report(
                            new DeliveryError('privacy_finalizer_failed', null, false, 0, $error),
                            EventBuilder::batch([]),
                        );
                    }
                }
                if ([] !== $events) {
                    $batch = EventBuilder::batch($events);
                    try {
                        $this->emitter->emit($batch);
                    } catch (\Throwable $error) {
                        $this->report(
                            $error instanceof DeliveryError
                                ? $error
                                : new DeliveryError('emitter_failed', null, false, 1, $error),
                            $batch,
                        );
                    }
                }
            }
        } finally {
            $this->draining = false;
        }
    }

    public function close(): void
    {
        if (!$this->accepting) {
            return;
        }
        $this->accepting = false;
        $this->flush();
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    public function pending(): int
    {
        return $this->pending->count();
    }

    private function schedule(): void
    {
        if ($this->scheduled || null === $this->config->scheduler) {
            return;
        }
        $this->scheduled = true;
        try {
            $this->config->scheduler->schedule(function (): void {
                $this->flush();
            });
        } catch (\Throwable $error) {
            $this->scheduled = false;
            $this->report(
                new DeliveryError('scheduler_failed', null, false, 0, $error),
                EventBuilder::batch([]),
            );
        }
    }

    /**
     * @param array{schema_version: 1, events: list<array<string, mixed>>} $batch
     */
    private function report(\Throwable $error, array $batch): void
    {
        if (!\is_callable($this->config->onError)) {
            return;
        }
        try {
            ($this->config->onError)($error, $batch);
        } catch (\Throwable) {
            // Analytics diagnostics must never fail a customer tool.
        }
    }
}
