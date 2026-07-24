<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Event\BoundedKeySet;
use Armature\McpAnalytics\Event\EventBuilder;
use Armature\McpAnalytics\Event\Identity;
use Armature\McpAnalytics\Event\SessionIds;
use Armature\McpAnalytics\StatelessSession;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testIdentityAndRequestScopingAreStable(): void
    {
        $actor = Identity::actorId('seed');
        self::assertSame(\hash('sha256', 'seed'), $actor);
        self::assertSame(
            \hash('sha256', $actor . ' tool_call session-a#5'),
            Identity::eventId($actor, SessionIds::requestId('5', 'session-a'), 'tool_call'),
        );
        self::assertNotSame(
            SessionIds::requestId('5', 'session-a'),
            SessionIds::requestId('5', 'session-b'),
        );
    }

    public function testStatelessSessionRoundTripAndUnknownIdentity(): void
    {
        $seed = '019f942a-5d64-4322-8e50-5d17333768d9';
        $session = SessionIds::buildStateless(
            ['name' => 'Claude Desktop', 'version' => '1 beta'],
            $seed,
        );
        self::assertSame(
            'mcp_Claude-Desktop_v_1-beta_' . $seed,
            $session,
        );
        self::assertSame(
            ['name' => 'Claude-Desktop', 'version' => '1-beta'],
            SessionIds::parseClientInfo($session),
        );
        self::assertNull(SessionIds::parseClientInfo(SessionIds::buildStateless(null, $seed)));
    }

    public function testPublicStatelessSessionFacadeMatchesInternalIdentityRules(): void
    {
        $seed = '019f942a-5d64-4322-8e50-5d17333768d9';
        $id = StatelessSession::buildId(['name' => 'Codex', 'version' => '5.0'], $seed);

        self::assertSame('mcp_Codex_v_5.0_' . $seed, $id);
        self::assertSame(
            ['name' => 'Codex', 'version' => '5.0'],
            StatelessSession::parseClientInfo($id),
        );
    }

    public function testStatelessSessionAcceptsTimeOrderedUuidV7Seeds(): void
    {
        $seed = '019f942a-5d64-7322-8e50-5d17333768d9';

        self::assertSame(
            'mcp_Codex_v__' . $seed,
            StatelessSession::buildId(['name' => 'Codex'], $seed),
        );
    }

    public function testBoundedKeysEvictInFifoOrder(): void
    {
        $keys = new BoundedKeySet(2);
        $keys->add('a');
        $keys->add('b');
        $keys->add('c');
        self::assertFalse($keys->has('a'));
        self::assertTrue($keys->has('b'));
        self::assertTrue($keys->has('c'));
    }

    public function testTelemetrySanitizationVectorsReachEventMetadata(): void
    {
        $decoded = \json_decode(
            (string) \file_get_contents(__DIR__ . '/../Fixtures/telemetry-contract-vectors.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        $vectors = $decoded['telemetry_sanitization'] ?? null;
        self::assertIsArray($vectors);

        foreach ($vectors as $vector) {
            self::assertIsArray($vector);
            self::assertIsArray($vector['telemetry']);
            $event = EventBuilder::toolCall([
                'tool_name' => 't',
                'telemetry' => [
                    'user_intent' => \implode('', $vector['telemetry']['user_intent']),
                    'agent_thinking' => \implode('', $vector['telemetry']['agent_thinking']),
                ],
                'input' => [],
                'status' => 'ok',
                'duration_ms' => 1,
                'actor_id' => 'actor',
                'request_id' => 'req-' . $vector['name'],
                'started_at' => '1970-01-01T00:00:00.000Z',
                'finished_at' => '1970-01-01T00:00:00.001Z',
            ]);
            self::assertNotNull($event);
            self::assertSame(
                $vector['expect']['user_intent'],
                $event['metadata']['user_intent'],
                $vector['name'] . ': user_intent',
            );
            self::assertSame(
                $vector['expect']['agent_thinking'],
                $event['metadata']['agent_thinking'],
                $vector['name'] . ': agent_thinking',
            );
        }
    }

    public function testToolEventUsesUtf8SafeBoundedPreviewsAndLegacyMirrors(): void
    {
        $event = EventBuilder::toolCall([
            'tool_name' => 'weather',
            'telemetry' => [
                'user_intent' => 'get weather',
                'agent_thinking' => 'call tool',
                'user_frustration' => 'low',
            ],
            'input' => ['city' => \str_repeat('😀', 10_000)],
            'output' => ['ok' => true],
            'status' => 'ok',
            'duration_ms' => 4,
            'actor_id' => 'actor',
            'session_id' => 'session',
            'request_id' => 'request',
            'started_at' => '2026-07-24T00:00:00.000Z',
            'finished_at' => '2026-07-24T00:00:00.004Z',
            'workflow_run_id' => '019f942a-5d64-7322-8e50-5d17333768d9',
        ]);
        self::assertNotNull($event);
        self::assertTrue($event['script_source_truncated']);
        self::assertTrue(\mb_check_encoding($event['script_source'], 'UTF-8'));
        self::assertSame('get weather', $event['metadata']['intent']);
        self::assertSame('call tool', $event['metadata']['context']);
        self::assertSame('low', $event['metadata']['frustration_level']);
        self::assertTrue($event['is_workflow']);
    }

    public function testWholeEventHookCanMutateDropAndFailsClosed(): void
    {
        $base = [
            'tool_name' => 'tool',
            'input' => ['secret' => 'leak'],
            'status' => 'error',
            'error_message' => 'leak-error',
            'duration_ms' => 1,
            'actor_id' => 'actor',
            'request_id' => 'request',
            'started_at' => 'start',
            'finished_at' => 'finish',
        ];

        self::assertNull(EventBuilder::toolCall($base, redactEvent: static fn (): mixed => null));
        $event = EventBuilder::toolCall(
            $base,
            redactEvent: static function (): never {
                throw new \RuntimeException('failed');
            },
        );
        self::assertNotNull($event);
        self::assertSame('"[redaction failed]"', $event['metadata']['input_preview']);
        self::assertSame('[redaction failed]', $event['error']);
        self::assertStringNotContainsString('leak', \json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function testSessionInitIsStableAndCapabilitiesAreBounded(): void
    {
        $first = EventBuilder::sessionInit(
            'actor',
            'session',
            'now',
            ['name' => 'client', 'version' => '1', 'capabilities' => ['x' => true]],
            ['User-Agent' => 'agent'],
        );
        $second = EventBuilder::sessionInit('actor', 'session', 'later');
        self::assertSame($first['event_id'], $second['event_id']);
        self::assertSame('client', $first['metadata']['client_name']);
        self::assertSame(['x' => true], $first['metadata']['capabilities']);
    }
}
