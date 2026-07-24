<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Integration;

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Delivery\EmitterInterface;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class HttpE2eTest extends TestCase
{
    public function testRealOfficialStreamableHttpSessionAndRequestContext(): void
    {
        $emitter = new HttpEmitter();
        $store = new InMemorySessionStore();
        $builder = Server::builder()
            ->setServerInfo('http-test', '1.0.0')
            ->setSession(sessionStore: $store);
        $analytics = Analytics::instrument(
            $builder,
            new Config(emitter: $emitter, requestCapability: false),
        );
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
        $builder->addTool(
            static fn (): string => 'ok',
            name: 'no_arguments',
            description: 'No arguments',
            inputSchema: ['type' => 'object', 'properties' => []],
        );
        $server = $builder->build();
        $factory = new Psr17Factory();

        $initialize = self::request([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'http-client', 'version' => '2.0'],
            ],
        ])->withAttribute('principal_id', 'principal-123');
        $initializeResponse = $server->run(new StreamableHttpTransport(
            request: $initialize,
            responseFactory: $factory,
            streamFactory: $factory,
            middleware: [
                ...StreamableHttpTransport::defaultMiddleware(),
                $analytics->httpMiddleware(),
            ],
        ));
        self::assertSame(200, $initializeResponse->getStatusCode());
        $sessionId = $initializeResponse->getHeaderLine('Mcp-Session-Id');
        self::assertNotSame('', $sessionId);

        $listResponse = $server->run(new StreamableHttpTransport(
            request: self::request([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => [],
            ], [
                'Mcp-Session-Id' => $sessionId,
                'Mcp-Protocol-Version' => '2025-11-25',
            ]),
            responseFactory: $factory,
            streamFactory: $factory,
            middleware: [
                ...StreamableHttpTransport::defaultMiddleware(),
                $analytics->httpMiddleware(),
            ],
        ));
        self::assertSame(200, $listResponse->getStatusCode());
        $listed = \json_decode((string) $listResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $noArguments = \array_values(\array_filter(
            $listed['result']['tools'] ?? [],
            static fn (array $tool): bool => 'no_arguments' === ($tool['name'] ?? null),
        ));
        self::assertCount(1, $noArguments);
        self::assertSame([], $noArguments[0]['inputSchema']['required']);

        $workflowRunId = '019f942a-5d64-7322-8e50-5d17333768d9';
        $call = self::request([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
                'arguments' => [
                    'text' => 'hello',
                    'telemetry' => [
                        'user_intent' => 'exercise HTTP',
                        'agent_thinking' => 'the HTTP call checks request context',
                    ],
                ],
            ],
        ], [
            'Authorization' => 'Bearer never-export-this',
            'Mcp-Session-Id' => $sessionId,
            'Mcp-Protocol-Version' => '2025-11-25',
            'X-Armature-Workflow-Run-Id' => $workflowRunId,
            'User-Agent' => 'phpunit-http-client',
        ])->withAttribute('principal_id', 'principal-123');
        $callResponse = $server->run(new StreamableHttpTransport(
            request: $call,
            responseFactory: $factory,
            streamFactory: $factory,
            middleware: [
                ...StreamableHttpTransport::defaultMiddleware(),
                $analytics->httpMiddleware(),
            ],
        ));

        self::assertSame(200, $callResponse->getStatusCode());
        $payload = \json_decode((string) $callResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('echo: hello', $payload['result']['content'][0]['text']);
        $sessionEvent = $emitter->event('session_init');
        $toolEvent = $emitter->event('tool_call');
        self::assertSame($sessionId, $sessionEvent['session_id_hint']);
        self::assertSame($sessionId, $toolEvent['session_id_hint']);
        self::assertSame('http-client', $sessionEvent['metadata']['client_name']);
        self::assertSame('phpunit-http-client', $sessionEvent['metadata']['user_agent']);
        self::assertSame('2025-11-25', $sessionEvent['metadata']['protocol_version']);
        self::assertSame(\hash('sha256', 'principal-123'), $toolEvent['actor_id']);
        self::assertTrue($toolEvent['is_workflow']);
        self::assertSame($workflowRunId, $toolEvent['workflow_run_id']);
        self::assertStringNotContainsString(
            'never-export-this',
            \json_encode($emitter->batches, JSON_THROW_ON_ERROR),
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
            ['Content-Type' => 'application/json', ...$headers],
            \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}

final class HttpEmitter implements EmitterInterface
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
        foreach ($this->batches as $batch) {
            foreach ($batch['events'] as $event) {
                if ($kind === ($event['kind'] ?? null)) {
                    return $event;
                }
            }
        }

        TestCase::fail('No ' . $kind . ' event was emitted.');
    }
}
