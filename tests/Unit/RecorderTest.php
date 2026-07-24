<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Delivery\EmitterInterface;
use Armature\McpAnalytics\Recorder;
use Armature\McpAnalytics\TelemetryMode;
use PHPUnit\Framework\TestCase;

final class RecorderTest extends TestCase
{
    public function testInstrumentedCallStripsInjectedTelemetryAndPreservesResult(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(new Config(emitter: $emitter), $emitter);
        $seen = null;
        $result = ['content' => [['type' => 'text', 'text' => 'sunny']]];

        $returned = $recorder->instrumentToolCall(
            'weather',
            ['city' => 'Paris', 'telemetry' => ['user_intent' => 'check weather']],
            static function (mixed $arguments) use (&$seen, $result): array {
                $seen = $arguments;

                return $result;
            },
            sessionId: 'session',
            requestId: '5',
        );

        self::assertSame(['city' => 'Paris'], $seen);
        self::assertSame($result, $returned);
        $tool = $emitter->event('tool_call');
        self::assertSame('check weather', $tool['metadata']['user_intent']);
        self::assertSame('session', $tool['session_id_hint']);
        self::assertSame(\hash('sha256', $tool['actor_id'] . ' tool_call session#5'), $tool['event_id']);
    }

    public function testOwnedTelemetryReachesHandlerAndIsNeverExported(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(new Config(emitter: $emitter), $emitter);
        $seen = null;

        $recorder->instrumentToolCall(
            'owned',
            ['telemetry' => ['customer' => 'value']],
            static function (mixed $arguments) use (&$seen): string {
                $seen = $arguments;

                return 'ok';
            },
            TelemetryMode::Owned,
            headers: [],
        );

        self::assertSame(['telemetry' => ['customer' => 'value']], $seen);
        $tool = $emitter->event('tool_call');
        self::assertNull($tool['metadata']['user_intent']);
        self::assertStringContainsString('telemetry', $tool['metadata']['input_preview']);
    }

    public function testOwnedToolCanExportOnlyExplicitlyMappedCustomerField(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(
            new Config(
                emitter: $emitter,
                telemetryFieldMap: ['user_intent' => 'purpose'],
            ),
            $emitter,
        );
        $seen = null;
        $arguments = [
            'purpose' => 'book a flight',
            'telemetry' => ['user_intent' => 'customer-owned value'],
        ];

        $recorder->instrumentToolCall(
            'owned',
            $arguments,
            static function (mixed $handlerArguments) use (&$seen): string {
                $seen = $handlerArguments;

                return 'ok';
            },
            TelemetryMode::Owned,
            headers: [],
        );

        self::assertSame($arguments, $seen);
        $tool = $emitter->event('tool_call');
        self::assertSame('book a flight', $tool['metadata']['user_intent']);
        self::assertNotSame('customer-owned value', $tool['metadata']['user_intent']);
    }

    public function testScrubModeDropsCachedTelemetryFromHandlerAndSink(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(new Config(captureTelemetry: false, emitter: $emitter), $emitter);
        $seen = null;

        $recorder->instrumentToolCall(
            'scrub',
            ['safe' => true, 'telemetry' => ['user_intent' => 'never-export']],
            static function (mixed $arguments) use (&$seen): string {
                $seen = $arguments;

                return 'ok';
            },
            TelemetryMode::Scrub,
            headers: [],
        );

        self::assertSame(['safe' => true], $seen);
        self::assertStringNotContainsString('never-export', \json_encode($emitter->batches, JSON_THROW_ON_ERROR));
    }

    public function testThrownExceptionIdentityIsPreservedAndRecorded(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(new Config(emitter: $emitter), $emitter);
        $original = new \RuntimeException('boom');

        try {
            $recorder->instrumentToolCall(
                'explode',
                [],
                static function () use ($original): never {
                    throw $original;
                },
            );
            self::fail('Expected exception.');
        } catch (\Throwable $caught) {
            self::assertSame($original, $caught);
        }
        $tool = $emitter->event('tool_call');
        self::assertFalse($tool['ok']);
        self::assertSame('boom', $tool['error']);
        self::assertNull($tool['result_preview']);
    }

    public function testCallToolErrorResultIsRecordedAsFailureButReturnedExactly(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(new Config(emitter: $emitter), $emitter);
        $result = [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => 'upstream failed']],
        ];

        $returned = $recorder->instrumentToolCall('error-result', [], static fn (): array => $result);
        self::assertSame($result, $returned);
        $tool = $emitter->event('tool_call');
        self::assertFalse($tool['ok']);
        self::assertSame('upstream failed', $tool['error']);
    }

    public function testActorIdentifierPrecedesOtherSeedsAndEmitsOnlyOnChange(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(
            new Config(actorIdentifier: 'customer@example.test', actorId: 'ignored', emitter: $emitter),
            $emitter,
        );
        $recorder->recordToolCall('one', headers: ['Authorization' => 'Bearer ignored']);
        $recorder->recordToolCall('two', headers: ['Authorization' => 'Bearer ignored']);

        $events = $emitter->events('actor_identity');
        self::assertCount(1, $events);
        self::assertSame('customer@example.test', $events[0]['metadata']['identifier']);
        self::assertSame(\hash('sha256', 'customer@example.test'), $emitter->event('tool_call')['actor_id']);
    }

    public function testSessionInitIsDeduplicatedAndStdioGetsProcessSession(): void
    {
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(new Config(emitter: $emitter), $emitter);
        $recorder->recordToolCall('one');
        $recorder->recordToolCall('two');
        self::assertCount(1, $emitter->events('session_init'));
        $toolEvents = $emitter->events('tool_call');
        self::assertStringStartsWith('stdio-', $toolEvents[0]['session_id_hint']);
        self::assertSame($toolEvents[0]['session_id_hint'], $toolEvents[1]['session_id_hint']);
    }

    public function testCaptureOffDropsDirectTelemetryBeforeActorCallback(): void
    {
        $observed = [];
        $emitter = new RecorderEmitter();
        $recorder = new Recorder(
            new Config(
                captureTelemetry: false,
                actorId: static function (array $context) use (&$observed): string {
                    $observed[] = [
                        $context['telemetry'] ?? null,
                        $context['session_id'] ?? null,
                    ];

                    return 'actor';
                },
                emitter: $emitter,
            ),
            $emitter,
        );
        $recorder->recordToolCall(
            'off',
            telemetry: ['user_intent' => 'private'],
            sessionId: 'actor-context-session',
            headers: [],
        );
        self::assertSame([[null, 'actor-context-session']], $observed);
        self::assertStringNotContainsString('private', \json_encode($emitter->batches, JSON_THROW_ON_ERROR));
    }
}

final class RecorderEmitter implements EmitterInterface
{
    /** @var list<array{schema_version: 1, events: list<array<string, mixed>>}> */
    public array $batches = [];

    public function emit(array $batch): void
    {
        $this->batches[] = $batch;
    }

    /**
     * @return array<string, mixed>
     */
    public function event(string $kind): array
    {
        $events = $this->events($kind);
        TestCase::assertNotEmpty($events);

        return $events[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(string $kind): array
    {
        $events = [];
        foreach ($this->batches as $batch) {
            foreach ($batch['events'] as $event) {
                if ($kind === ($event['kind'] ?? null)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }
}
