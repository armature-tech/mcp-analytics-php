<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Adapter\Official\RequestContextStore;
use PHPUnit\Framework\TestCase;

final class RequestContextStoreTest extends TestCase
{
    public function testChildFiberUsesTheOnlyRequestInFlight(): void
    {
        $store = new RequestContextStore();
        $alice = self::context('alice');

        $request = new \Fiber(static fn (): mixed => $store->run($alice, static function () use ($store): mixed {
            $child = new \Fiber(static fn (): mixed => $store->current());
            $child->start();

            return $child->getReturn();
        }));
        $request->start();

        self::assertSame($alice, $request->getReturn());
        self::assertNull($store->current());
    }

    public function testOnlyASessionlessCallBorrowsASessionlessRequest(): void
    {
        $store = new RequestContextStore();
        $legacy = ['headers' => ['Mcp-Session-Id' => 'legacy-1'], 'attributes' => ['principal_id' => 'carol']];
        // A call naming another session never borrows this request.
        self::assertNull(self::lookupInChild($store, self::context('alice'), 'other-session'));

        // A sessionless call never borrows a request that has a session.
        self::assertNull(self::lookupInChild($store, $legacy, null));
    }

    public function testChildFiberGetsNoContextWhenRequestsOverlap(): void
    {
        $store = new RequestContextStore();
        $seen = [];
        $request = static function (string $principal) use ($store, &$seen): \Fiber {
            return new \Fiber(static function () use ($store, $principal, &$seen): void {
                $store->run(self::context($principal), static function () use ($store, &$seen): void {
                    \Fiber::suspend();
                    $child = new \Fiber(static fn (): mixed => $store->current());
                    $child->start();
                    $seen[] = $child->getReturn();
                });
            });
        };

        $alice = $request('alice');
        $bob = $request('bob');
        $alice->start();
        $bob->start();
        $alice->resume();
        $bob->resume();

        self::assertSame([null, self::context('bob')], $seen);
    }

    /**
     * Runs one request in its own Fiber and looks the context up from a
     * child Fiber the middleware never entered.
     *
     * @param array{headers: array<string, string>, attributes: array<string, mixed>} $context
     */
    private static function lookupInChild(RequestContextStore $store, array $context, ?string $sessionId): mixed
    {
        $request = new \Fiber(static fn (): mixed => $store->run($context, static function () use ($store, $sessionId): mixed {
            $child = new \Fiber(static fn (): mixed => $store->current($sessionId));
            $child->start();

            return $child->getReturn();
        }));
        $request->start();

        return $request->getReturn();
    }

    /**
     * @return array{headers: array<string, string>, attributes: array<string, mixed>}
     */
    private static function context(string $principal): array
    {
        return ['headers' => [], 'attributes' => ['principal_id' => $principal]];
    }
}
