<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Integration;

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Contract\FeedbackInstructions;
use Armature\McpAnalytics\Instrumentation;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The send_feedback sentence reaches Claude Code's server instructions on
 * both protocol eras, and no other client.
 */
final class FeedbackInstructionsE2eTest extends TestCase
{
    private const SERVER_INSTRUCTIONS = 'Use these tools to manage signature requests.';
    private const MODERN = '2026-07-28';

    public function testClaudeCodeInitializeGetsTheSentenceAfterTheServerInstructions(): void
    {
        [$server, $analytics] = self::server();

        $response = self::initialize($server, $analytics, 'claude-code');

        self::assertSame(
            self::SERVER_INSTRUCTIONS . "\n\n" . FeedbackInstructions::SENTENCE,
            self::resultOf($response)['instructions'],
        );
        self::assertNotSame('', $response->getHeaderLine('Mcp-Session-Id'));
    }

    public function testOtherClientsGetTheServerInstructionsUnchanged(): void
    {
        [$server, $analytics] = self::server();

        foreach (['Anthropic/ClaudeAI', 'claude-ai', 'Claude', 'openai-mcp', 'cursor'] as $client) {
            $response = self::initialize($server, $analytics, $client);
            self::assertSame(self::SERVER_INSTRUCTIONS, self::resultOf($response)['instructions'], $client);
        }
    }

    public function testServerWithoutInstructionsGetsTheSentenceAlone(): void
    {
        [$server, $analytics] = self::server(instructions: null);

        $response = self::initialize($server, $analytics, 'claude-code');

        self::assertSame(FeedbackInstructions::SENTENCE, self::resultOf($response)['instructions']);
    }

    public function testNoSentenceWhenTheSdkDoesNotServeSendFeedback(): void
    {
        foreach ([
            'send_feedback off' => new Config(emitter: new HttpEmitter(), sendFeedback: false),
            'instructions off' => new Config(emitter: new HttpEmitter(), feedbackInstructions: false),
            'no delivery path' => new Config(),
        ] as $case => $config) {
            [$server, $analytics] = self::server(config: $config);
            $response = self::initialize($server, $analytics, 'claude-code');
            self::assertSame(self::SERVER_INSTRUCTIONS, self::resultOf($response)['instructions'], $case);
        }
    }

    public function testNoSentenceWhenTheServerOwnsATool(): void
    {
        [$server, $analytics] = self::server(customerFeedbackTool: true);

        $response = self::initialize($server, $analytics, 'claude-code');

        self::assertSame(self::SERVER_INSTRUCTIONS, self::resultOf($response)['instructions']);
    }

    public function testClaudeCodeDiscoverGetsTheSentence(): void
    {
        if (!\class_exists('Mcp\Server\Stateless\StatelessProtocol')) {
            self::markTestSkipped('mcp/sdk 0.7 does not serve the 2026-07-28 revision.');
        }

        foreach (['claude-code' => true, 'Anthropic/ClaudeAI' => false] as $client => $expected) {
            [$server, $analytics] = self::server();
            $response = $server->run(self::transport($analytics, self::request([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'server/discover',
                'params' => ['_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::MODERN,
                    'io.modelcontextprotocol/clientInfo' => ['name' => $client, 'version' => '2.1.318'],
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ]],
            ], [
                'Mcp-Protocol-Version' => self::MODERN,
                'Mcp-Method' => 'server/discover',
            ])));

            $result = self::resultOf($response);
            self::assertSame([self::MODERN], $result['supportedVersions']);
            self::assertSame(
                $expected ? self::SERVER_INSTRUCTIONS . "\n\n" . FeedbackInstructions::SENTENCE : self::SERVER_INSTRUCTIONS,
                $result['instructions'],
                $client,
            );
            // Empty JSON objects stay objects.
            self::assertStringNotContainsString('"tools":[]', (string) $response->getBody());
        }
    }

    public function testToolCallsPassThroughUntouched(): void
    {
        [$server, $analytics] = self::server();
        $sessionId = self::initialize($server, $analytics, 'claude-code')->getHeaderLine('Mcp-Session-Id');

        $response = $server->run(self::transport($analytics, self::request([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'initialize']],
        ], [
            'Mcp-Session-Id' => $sessionId,
            'Mcp-Protocol-Version' => '2025-11-25',
        ])));

        self::assertSame('echo: initialize', self::resultOf($response)['content'][0]['text']);
        self::assertStringNotContainsString('send_feedback', (string) $response->getBody());
    }

    public function testWithFeedbackInstructionsRewritesRawBodies(): void
    {
        [$server, $analytics] = self::server();
        $request = self::request(self::initializeBody('claude-code'));
        $response = $server->run(new StreamableHttpTransport(
            request: $request,
            responseFactory: new Psr17Factory(),
            streamFactory: new Psr17Factory(),
        ));
        $responseBody = (string) $response->getBody();
        self::assertSame(self::SERVER_INSTRUCTIONS, self::resultOf($response)['instructions']);

        $rewritten = $analytics->withFeedbackInstructions((string) $request->getBody(), $responseBody);

        self::assertNotNull($rewritten);
        $payload = \json_decode($rewritten, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::SERVER_INSTRUCTIONS . "\n\n" . FeedbackInstructions::SENTENCE, $payload['result']['instructions']);
        self::assertNull($analytics->withFeedbackInstructions(
            (string) self::request(self::initializeBody('Anthropic/ClaudeAI'))->getBody(),
            $responseBody,
        ));
        self::assertNull($analytics->withFeedbackInstructions((string) $request->getBody(), $rewritten));
    }

    /**
     * @return array{Server, Instrumentation}
     */
    private static function server(
        ?string $instructions = self::SERVER_INSTRUCTIONS,
        ?Config $config = null,
        bool $customerFeedbackTool = false,
    ): array {
        $builder = Server::builder()->setServerInfo('feedback-instructions', '1.0.0');
        if (null !== $instructions) {
            $builder->setInstructions($instructions);
        }
        $analytics = Analytics::instrument($builder, $config ?? new Config(emitter: new HttpEmitter()));
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
        if ($customerFeedbackTool) {
            $builder->addTool(
                static fn (string $message): string => 'thanks',
                name: 'send_feedback',
                description: 'Send us feedback',
                inputSchema: [
                    'type' => 'object',
                    'properties' => ['message' => ['type' => 'string']],
                    'required' => ['message'],
                ],
            );
        }

        return [$builder->build(), $analytics];
    }

    private static function initialize(Server $server, Instrumentation $analytics, string $client): ResponseInterface
    {
        $response = $server->run(self::transport($analytics, self::request(self::initializeBody($client))));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function initializeBody(string $client): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => $client, 'version' => '1.0'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultOf(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), $body);
        $payload = \json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['result'] ?? null, $body);

        return $payload['result'];
    }

    private static function transport(Instrumentation $analytics, ServerRequest $request): StreamableHttpTransport
    {
        $factory = new Psr17Factory();

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
