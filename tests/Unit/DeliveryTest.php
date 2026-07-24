<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Delivery\DeliveryError;
use Armature\McpAnalytics\Delivery\EmitterInterface;
use Armature\McpAnalytics\Delivery\IngestResponse;
use Armature\McpAnalytics\Delivery\PrivacyQueue;
use Armature\McpAnalytics\Delivery\SchedulerInterface;
use Armature\McpAnalytics\Delivery\SymfonyIngestEmitter;
use Armature\McpAnalytics\DeliveryMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeliveryTest extends TestCase
{
    public function testAwaitQueueBatchesAndDoesNotEscapeEmitterFailure(): void
    {
        $errors = [];
        $emitter = new class implements EmitterInterface {
            public int $calls = 0;

            public function emit(array $batch): void
            {
                ++$this->calls;
                throw new \RuntimeException('sink failed');
            }
        };
        $queue = new PrivacyQueue(
            new Config(
                emitter: $emitter,
                onError: static function (\Throwable $error, array $batch) use (&$errors): void {
                    self::assertInstanceOf(DeliveryError::class, $error);
                    $errors[] = [
                        $error->errorCode,
                        $error->causeClass,
                        $error->getMessage(),
                        $error->getPrevious(),
                        \count($batch['events']),
                    ];
                },
            ),
            $emitter,
        );
        $queue->enqueue(static fn (): array => [self::event('1')]);
        self::assertSame(1, $emitter->calls);
        self::assertSame([
            ['emitter_failed', \RuntimeException::class, 'emitter_failed', null, 1],
        ], $errors);
    }

    public function testFinalizerFailureReportsNoCandidateOrExceptionMessage(): void
    {
        $errors = [];
        $emitter = new CollectingEmitter();
        $queue = new PrivacyQueue(
            new Config(
                emitter: $emitter,
                onError: static function (\Throwable $error, array $batch) use (&$errors): void {
                    self::assertInstanceOf(DeliveryError::class, $error);
                    $errors[] = [
                        $error->errorCode,
                        $error->causeClass,
                        $error->getMessage(),
                        $error->getPrevious(),
                        $batch,
                    ];
                },
            ),
            $emitter,
        );

        $queue->enqueue(static function (): never {
            throw new \RuntimeException('private candidate value');
        });

        self::assertSame([
            [
                'privacy_finalizer_failed',
                \RuntimeException::class,
                'privacy_finalizer_failed',
                null,
                ['schema_version' => 1, 'events' => []],
            ],
        ], $errors);
        self::assertSame([], $emitter->eventIds);
    }

    public function testDeferredQueueSchedulesOneCoalescedDrain(): void
    {
        $scheduler = new TestScheduler();
        $emitter = new CollectingEmitter();
        $queue = new PrivacyQueue(
            new Config(
                delivery: DeliveryMode::Deferred,
                emitter: $emitter,
                scheduler: $scheduler,
            ),
            $emitter,
        );
        $queue->enqueue(static fn (): array => [self::event('1')]);
        $queue->enqueue(static fn (): array => [self::event('2')]);
        self::assertCount(1, $scheduler->tasks);
        self::assertSame(2, $queue->pending());
        ($scheduler->tasks[0])();
        self::assertSame([['1', '2']], $emitter->eventIds);
    }

    public function testCloseDrainsAndRejectsNewCandidates(): void
    {
        $scheduler = new TestScheduler();
        $emitter = new CollectingEmitter();
        $queue = new PrivacyQueue(
            new Config(
                delivery: DeliveryMode::Deferred,
                emitter: $emitter,
                scheduler: $scheduler,
            ),
            $emitter,
        );
        $queue->enqueue(static fn (): array => [self::event('1')]);
        $queue->close();
        $queue->enqueue(static fn (): array => [self::event('2')]);
        self::assertSame([['1']], $emitter->eventIds);
    }

    public function testOverflowDropsOldestWarnsOnceAndDrainsInTwentyEventBatches(): void
    {
        $scheduler = new TestScheduler();
        $emitter = new CollectingEmitter();
        $queue = new PrivacyQueue(
            new Config(
                delivery: DeliveryMode::Deferred,
                emitter: $emitter,
                scheduler: $scheduler,
            ),
            $emitter,
        );
        $warnings = [];
        \set_error_handler(
            static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = [$severity, $message];

                return true;
            },
        );
        try {
            for ($index = 0; $index <= PrivacyQueue::CAPACITY; ++$index) {
                $queue->enqueue(static fn (): array => [self::event((string) $index)]);
            }
        } finally {
            \restore_error_handler();
        }
        self::assertSame(1, $queue->dropped());
        self::assertCount(1, $warnings);
        self::assertCount(1, $scheduler->tasks);
        ($scheduler->tasks[0])();
        self::assertCount(50, $emitter->eventIds);
        self::assertSame('1', $emitter->eventIds[0][0]);
        self::assertSame((string) PrivacyQueue::CAPACITY, $emitter->eventIds[49][19]);
    }

    public function testIngestRetries429ThenAccepts(): void
    {
        $delays = [];
        $client = new MockHttpClient([
            new MockResponse('{"error":{"code":"rate_limited"}}', ['http_code' => 429]),
            new MockResponse('{"accepted":1,"rejected":[]}', ['http_code' => 200]),
        ]);
        $emitter = new SymfonyIngestEmitter(
            'https://example.test/ingest',
            'test-key',
            client: $client,
            delay: static function (int $delay) use (&$delays): void {
                $delays[] = $delay;
            },
        );
        $emitter->emit(['schema_version' => 1, 'events' => [self::event('1')]]);
        self::assertSame([100], $delays);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testIngestDoesNotRetryOrdinary4xx(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"errorCode":"invalid_api_key"}', ['http_code' => 401]),
        );
        $emitter = new SymfonyIngestEmitter('https://example.test/ingest', 'test-key', client: $client);

        try {
            $emitter->emit(['schema_version' => 1, 'events' => [self::event('1')]]);
            self::fail('Expected delivery failure.');
        } catch (DeliveryError $error) {
            self::assertSame('invalid_api_key', $error->errorCode);
            self::assertSame(401, $error->status);
            self::assertFalse($error->retryable);
            self::assertSame(1, $error->attempts);
            self::assertStringNotContainsString('test-key', $error->getMessage());
        }
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testIngestRetriesTransportFailureAndExposesOnlySafeCauseClass(): void
    {
        $delays = [];
        $calls = 0;
        $client = new MockHttpClient(
            static function () use (&$calls): never {
                ++$calls;
                throw new TransportException('private transport detail');
            },
        );
        $emitter = new SymfonyIngestEmitter(
            'https://example.test/ingest',
            'test-key',
            client: $client,
            delay: static function (int $delay) use (&$delays): void {
                $delays[] = $delay;
            },
        );

        try {
            $emitter->emit(['schema_version' => 1, 'events' => [self::event('1')]]);
            self::fail('Expected delivery failure.');
        } catch (DeliveryError $error) {
            self::assertSame('ingest_transport_error', $error->errorCode);
            self::assertSame(2, $error->attempts);
            self::assertTrue($error->retryable);
            self::assertSame(TransportException::class, $error->causeClass);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private transport detail', $error->getMessage());
        }
        self::assertSame([100], $delays);
        self::assertSame(2, $calls);
    }

    public function testIngestAppliesIdleAndTotalTimeouts(): void
    {
        $optionsSeen = null;
        $client = new MockHttpClient(
            static function (string $_method, string $_url, array $options) use (&$optionsSeen): MockResponse {
                $optionsSeen = $options;

                return new MockResponse('{"accepted":1,"rejected":[]}', ['http_code' => 200]);
            },
        );
        $emitter = new SymfonyIngestEmitter(
            'https://example.test/ingest',
            'test-key',
            timeoutMs: 1_234,
            client: $client,
        );

        $emitter->emit(['schema_version' => 1, 'events' => [self::event('1')]]);

        self::assertIsArray($optionsSeen);
        self::assertSame(1.234, $optionsSeen['timeout']);
        self::assertSame(1.234, $optionsSeen['max_duration']);
    }

    public function testSuccessfulBodyCanRejectEvents(): void
    {
        $error = IngestResponse::rejection(
            '{"accepted":0,"rejected":[{"event_id":"e1","reason":"schema_version_mismatch"}]}',
            1,
        );
        self::assertInstanceOf(DeliveryError::class, $error);
        self::assertSame('schema_version_mismatch', $error->errorCode);
        self::assertFalse($error->retryable);
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $id): array
    {
        return ['event_id' => $id, 'kind' => 'tool_call'];
    }
}

final class TestScheduler implements SchedulerInterface
{
    /** @var list<\Closure(): void> */
    public array $tasks = [];

    public function schedule(\Closure $task): void
    {
        $this->tasks[] = $task;
    }
}

final class CollectingEmitter implements EmitterInterface
{
    /** @var list<list<string>> */
    public array $eventIds = [];

    public function emit(array $batch): void
    {
        $this->eventIds[] = \array_map(
            static fn (array $event): string => (string) $event['event_id'],
            $batch['events'],
        );
    }
}
