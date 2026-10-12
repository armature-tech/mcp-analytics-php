<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Integration;

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The stateless 2026-07-28 revision, served by mcp/sdk 0.8 and later.
 */
final class StatelessHttpE2eTest extends TestCase
{
    private const PROTOCOL = '2026-07-28';

    /** @var list<array<string, mixed>> */
    private static array $handled = [];

    protected function setUp(): void
    {
        if (!\class_exists('Mcp\Server\Stateless\StatelessProtocol')) {
            self::markTestSkipped('mcp/sdk 0.7 does not serve the 2026-07-28 revision.');
        }
        self::$handled = [];
    }

    public function testStatelessToolCallsSendNoSessionIdAndCarryTheClient(): void
    {
        $emitter = new HttpEmitter();
        [$server, $analytics] = self::server($emitter);

        $listed = self::post($server, $analytics, 1, 'tools/list', [], self::meta('claude-code', '2.1.0'));
        $echo = \array_values(\array_filter(
            $listed['result']['tools'] ?? [],
            static fn (array $tool): bool => 'echo' === ($tool['name'] ?? null),
        ));
        self::assertCount(1, $echo);
        self::assertArrayHasKey('telemetry', $echo[0]['inputSchema']['properties']);

        foreach ([2, 3] as $id) {
            $payload = self::post($server, $analytics, $id, 'tools/call', [
                'name' => 'echo',
                'arguments' => [
                    'text' => 'hello ' . $id,
                    'telemetry' => ['user_intent' => 'exercise the stateless revision'],
                ],
            ], self::meta('claude-code', '2.1.0'));
            self::assertSame('echo: hello ' . $id, $payload['result']['content'][0]['text'] ?? null, \json_encode($payload, JSON_THROW_ON_ERROR));
        }

        // The customer handler never sees the telemetry argument.
        self::assertSame([['text' => 'hello 2'], ['text' => 'hello 3']], self::$handled);

        $analytics->flush();
        self::assertSame([], $emitter->events('session_init'));
        $calls = $emitter->events('tool_call');
        self::assertCount(2, $calls);
        foreach ($calls as $call) {
            self::assertNull($call['session_id_hint']);
            self::assertSame('claude-code', $call['metadata']['client_name']);
            self::assertSame('2.1.0', $call['metadata']['client_version']);
            self::assertSame(self::PROTOCOL, $call['metadata']['protocol_version']);
            self::assertSame('phpunit-stateless', $call['metadata']['user_agent']);
            self::assertSame(
                '{"roots":{"listChanged":true}}',
                \json_encode($call['metadata']['capabilities'], JSON_THROW_ON_ERROR),
            );
            self::assertSame('exercise the stateless revision', $call['metadata']['user_intent']);
            self::assertSame(\hash('sha256', 'principal-123'), $call['actor_id']);
        }
        self::assertNotSame($calls[0]['event_id'], $calls[1]['event_id']);
    }

    public function testStatelessCallKeepsTheActorWhenMiddlewareRunsInAFiber(): void
    {
        $emitter = new HttpEmitter();
        [$server, $analytics] = self::server($emitter);

        // An async server runs each request in its own Fiber; the SDK then
        // runs the tool in a child Fiber the middleware never entered.
        $fiber = new \Fiber(static fn (): array => self::post($server, $analytics, 1, 'tools/call', [
            'name' => 'echo',
            'arguments' => ['text' => 'in a fiber'],
        ], self::meta('claude-code', '2.1.0')));
        $fiber->start();
        self::assertTrue($fiber->isTerminated());
        self::assertSame('echo: in a fiber', $fiber->getReturn()['result']['content'][0]['text'] ?? null);

        $analytics->flush();
        $calls = $emitter->events('tool_call');
        self::assertCount(1, $calls);
        self::assertSame(\hash('sha256', 'principal-123'), $calls[0]['actor_id']);
        self::assertSame('phpunit-stateless', $calls[0]['metadata']['user_agent']);
    }

    public function testStatelessToolCallWithoutClientInfoIsStillRecorded(): void
    {
        $emitter = new HttpEmitter();
        [$server, $analytics] = self::server($emitter);

        $payload = self::post($server, $analytics, 1, 'tools/call', [
            'name' => 'echo',
            'arguments' => ['text' => 'anonymous'],
        ], self::meta(null, null));
        self::assertSame('echo: anonymous', $payload['result']['content'][0]['text'] ?? null, \json_encode($payload, JSON_THROW_ON_ERROR));

        $analytics->flush();
        $calls = $emitter->events('tool_call');
        self::assertCount(1, $calls);
        self::assertNull($calls[0]['session_id_hint']);
        self::assertNull($calls[0]['metadata']['client_name']);
        self::assertSame(self::PROTOCOL, $calls[0]['metadata']['protocol_version']);
    }

    public function testStatelessOnlyTransportRecordsTheCall(): void
    {
        $emitter = new HttpEmitter();
        $builder = Server::builder()->setServerInfo('stateless-only', '1.0.0');
        $analytics = Analytics::instrument($builder, new Config(emitter: $emitter, sendFeedback: false));
        $builder->addTool(
            static fn (string $text): string => 'echo: ' . $text,
            name: 'echo',
            description: 'Echo text',
            inputSchema: [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string']],
                'required' => ['text'],
            ],
        );
        $transport = self::statelessOnlyTransport(
            'Mcp\\Server\\Transport\\StatelessHttpTransport',
            $builder,
            $analytics,
        );
        $response = $transport->handle(self::request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
                'arguments' => ['text' => 'only', 'telemetry' => ['user_intent' => 'stateless only']],
                '_meta' => self::meta('cursor', '3.0'),
            ],
        ], [
            'Mcp-Protocol-Version' => self::PROTOCOL,
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'echo',
        ]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('echo: only', (string) $response->getBody());

        $analytics->flush();
        $calls = $emitter->events('tool_call');
        self::assertCount(1, $calls);
        self::assertNull($calls[0]['session_id_hint']);
        self::assertSame('cursor', $calls[0]['metadata']['client_name']);
        self::assertSame('stateless only', $calls[0]['metadata']['user_intent']);
    }

    public function testLegacySessionOnTheSameServerKeepsItsSessionId(): void
    {
        $emitter = new HttpEmitter();
        [$server, $analytics] = self::server($emitter);
        $factory = new Psr17Factory();

        $initialize = $server->run(self::transport($analytics, self::request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0'],
            ],
        ]), $factory));
        $sessionId = $initialize->getHeaderLine('Mcp-Session-Id');
        self::assertNotSame('', $sessionId);

        $call = $server->run(self::transport($analytics, self::request([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'legacy']],
        ], [
            'Mcp-Session-Id' => $sessionId,
            'Mcp-Protocol-Version' => '2025-11-25',
        ]), $factory));
        self::assertSame(200, $call->getStatusCode());

        $analytics->flush();
        $calls = $emitter->events('tool_call');
        self::assertCount(1, $calls);
        self::assertSame($sessionId, $calls[0]['session_id_hint']);
        // A call with a session id leaves client identity to session_init.
        self::assertArrayNotHasKey('client_name', $calls[0]['metadata']);
        $init = $emitter->event('session_init');
        self::assertSame($sessionId, $init['session_id_hint']);
        self::assertSame('legacy-client', $init['metadata']['client_name']);
    }

    /**
     * @return array{Server, \Armature\McpAnalytics\Instrumentation}
     */
    private static function server(HttpEmitter $emitter): array
    {
        $builder = Server::builder()->setServerInfo('stateless-test', '1.0.0');
        $analytics = Analytics::instrument(
            $builder,
            new Config(emitter: $emitter, sendFeedback: false),
        );
        $builder->addTool(
            static function (string $text): string {
                self::$handled[] = ['text' => $text];

                return 'echo: ' . $text;
            },
            name: 'echo',
            description: 'Echo text',
            inputSchema: [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string']],
                'required' => ['text'],
            ],
        );

        return [$builder->build(), $analytics];
    }

    /**
     * Untyped so the suite still type-checks against mcp/sdk 0.7, which has
     * neither this transport nor Builder::buildStateless().
     */
    private static function statelessOnlyTransport(
        string $class,
        mixed $builder,
        \Armature\McpAnalytics\Instrumentation $analytics,
    ): mixed {
        $factory = new Psr17Factory();

        return new $class(
            $builder->buildStateless(),
            $factory,
            $factory,
            middleware: [...$class::defaultMiddleware(), $analytics->httpMiddleware()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function meta(?string $name, ?string $version): array
    {
        $meta = [
            'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL,
            'io.modelcontextprotocol/clientCapabilities' => null === $name
                ? new \stdClass()
                : ['roots' => ['listChanged' => true]],
        ];
        if (null !== $name) {
            $meta['io.modelcontextprotocol/clientInfo'] = ['name' => $name, 'version' => $version];
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function post(
        Server $server,
        \Armature\McpAnalytics\Instrumentation $analytics,
        int $id,
        string $method,
        array $params,
        array $meta,
    ): array {
        $headers = [
            'Mcp-Protocol-Version' => self::PROTOCOL,
            'Mcp-Method' => $method,
            'User-Agent' => 'phpunit-stateless',
        ];
        if (isset($params['name']) && \is_string($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }
        $request = self::request([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => [...$params, '_meta' => $meta],
        ], $headers)->withAttribute('principal_id', 'principal-123');
        $response = $server->run(self::transport($analytics, $request, new Psr17Factory()));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('Mcp-Session-Id'));

        $body = (string) $response->getBody();
        if (\str_starts_with($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            \preg_match('/^data: (.+)$/m', $body, $match);
            $body = $match[1] ?? '';
        }

        return \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function transport(
        \Armature\McpAnalytics\Instrumentation $analytics,
        ServerRequest $request,
        Psr17Factory $factory,
    ): StreamableHttpTransport {
        return new StreamableHttpTransport(
            request: $request,
            responseFactory: $factory,
            streamFactory: $factory,
            middleware: [
                ...StreamableHttpTransport::defaultMiddleware(),
                $analytics->httpMiddleware(),
            ],
        );
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private static function request(array $body, array $headers = []): ServerRequest
    {
        return new ServerRequest(
            'POST',
            'http://localhost/mcp',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', ...$headers],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
