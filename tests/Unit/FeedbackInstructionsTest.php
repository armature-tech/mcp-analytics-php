<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Adapter\Official\AnalyticsHttpMiddleware;
use Armature\McpAnalytics\Adapter\Official\RequestContextStore;
use Armature\McpAnalytics\Contract\FeedbackInstructions;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FeedbackInstructionsTest extends TestCase
{
    public static ?string $handled = null;

    private const INITIALIZE_RESULT = '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25","capabilities":{"tools":{"listChanged":true},"logging":{}},"serverInfo":{"name":"s","version":"1"},"instructions":"Server text."}}';

    public function testInitializeFromClaudeCode(): void
    {
        $rewritten = FeedbackInstructions::rewrite(self::initialize('claude-code'), self::INITIALIZE_RESULT);

        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25","capabilities":{"tools":{"listChanged":true},"logging":{}},"serverInfo":{"name":"s","version":"1"},"instructions":"Server text.\n\n' . FeedbackInstructions::SENTENCE . '"}}',
            $rewritten,
        );
    }

    public function testDiscoverFromClaudeCode(): void
    {
        $request = '{"jsonrpc":"2.0","id":0,"method":"server\/discover","params":{"_meta":{"io.modelcontextprotocol\/clientInfo":{"name":"claude-code","version":"2.1.318"}}}}';
        $response = '{"jsonrpc":"2.0","id":0,"result":{"supportedVersions":["2026-07-28"],"capabilities":{},"resultType":"complete"}}';

        $payload = \json_decode((string) FeedbackInstructions::rewrite($request, $response), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(FeedbackInstructions::SENTENCE, $payload['result']['instructions']);
        self::assertSame(['2026-07-28'], $payload['result']['supportedVersions']);
    }

    public function testRequestsThatGetNothing(): void
    {
        foreach ([
            'other client' => self::initialize('Anthropic/ClaudeAI'),
            'no client info' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            'client name in the wrong place' => '{"jsonrpc":"2.0","id":0,"method":"server/discover","params":{"clientInfo":{"name":"claude-code"}}}',
            'other method' => '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"initialize","_meta":{"io.modelcontextprotocol/clientInfo":{"name":"claude-code"}}}}',
            'batch' => '[' . self::initialize('claude-code') . ']',
            'malformed' => '{"method":"initialize"',
            'empty' => '',
            'too large' => self::initialize('claude-code') . \str_repeat(' ', FeedbackInstructions::MAX_REQUEST_BYTES),
        ] as $case => $request) {
            self::assertFalse(FeedbackInstructions::applies($request), $case);
            self::assertNull(FeedbackInstructions::rewrite($request, self::INITIALIZE_RESULT), $case);
        }
    }

    public function testResponsesLeftAlone(): void
    {
        $request = self::initialize('claude-code');
        foreach ([
            'error' => '{"jsonrpc":"2.0","id":1,"error":{"code":-32600,"message":"bad"}}',
            'not json' => 'event: message',
            'instructions not a string' => '{"jsonrpc":"2.0","id":1,"result":{"instructions":["x"]}}',
            'sentence already there' => '{"jsonrpc":"2.0","id":1,"result":{"instructions":"' . FeedbackInstructions::SENTENCE . '"}}',
        ] as $case => $response) {
            self::assertNull(FeedbackInstructions::rewrite($request, $response), $case);
        }
    }

    public function testInstructions(): void
    {
        self::assertSame(FeedbackInstructions::SENTENCE, FeedbackInstructions::instructions(null));
        self::assertSame(FeedbackInstructions::SENTENCE, FeedbackInstructions::instructions("  \n"));
        self::assertSame("A.\n\n" . FeedbackInstructions::SENTENCE, FeedbackInstructions::instructions("A.\n"));
    }

    public function testMiddlewareRewritesAndKeepsContentLengthRight(): void
    {
        $middleware = self::middleware(static fn (): bool => true);
        $request = self::post(self::initialize('claude-code'));

        $response = $middleware->process($request, self::handler(new Response(
            200,
            ['Content-Type' => 'application/json', 'Content-Length' => (string) \strlen(self::INITIALIZE_RESULT)],
            self::INITIALIZE_RESULT,
        )));

        $body = (string) $response->getBody();
        self::assertStringEndsWith(FeedbackInstructions::SENTENCE . '"}}', $body);
        self::assertSame((string) \strlen($body), $response->getHeaderLine('Content-Length'));
        // The SDK still reads the whole request body.
        self::assertSame(self::initialize('claude-code'), (string) $request->getBody());
    }

    public function testMiddlewareReadsBodiesOfUnknownSize(): void
    {
        // Nyholm's ServerRequestCreator wraps php://input, which reports no size.
        $body = new UnknownSizeStream(self::initialize('claude-code'));
        $request = self::post('')->withBody($body);
        $json = ['Content-Type' => 'application/json'];

        $response = self::middleware(static fn (): bool => true)
            ->process($request, self::handler(new Response(200, $json, self::INITIALIZE_RESULT)));

        self::assertStringEndsWith(FeedbackInstructions::SENTENCE . '"}}', (string) $response->getBody());
        // The handler read the whole body from where the middleware left it.
        self::assertSame(self::initialize('claude-code'), self::$handled);

        $large = self::post('')->withBody(new UnknownSizeStream(
            self::initialize('claude-code') . \str_repeat(' ', FeedbackInstructions::MAX_REQUEST_BYTES),
        ));
        $untouched = new Response(200, $json, self::INITIALIZE_RESULT);
        self::assertSame($untouched, self::middleware(static fn (): bool => true)->process($large, self::handler($untouched)));
    }

    public function testMiddlewareLeavesEverythingElse(): void
    {
        $json = ['Content-Type' => 'application/json'];
        $nonSeekable = $this->createStub(StreamInterface::class);
        $nonSeekable->method('isSeekable')->willReturn(false);
        $nonSeekable->method('getSize')->willReturn(\strlen(self::initialize('claude-code')));
        $nonSeekable->method('__toString')->willReturn(self::initialize('claude-code'));
        foreach ([
            'gate closed' => [static fn (): bool => false, self::post(self::initialize('claude-code')), new Response(200, $json, self::INITIALIZE_RESULT)],
            'no gate' => [null, self::post(self::initialize('claude-code')), new Response(200, $json, self::INITIALIZE_RESULT)],
            'other client' => [static fn (): bool => true, self::post(self::initialize('openai-mcp')), new Response(200, $json, self::INITIALIZE_RESULT)],
            'event stream' => [static fn (): bool => true, self::post(self::initialize('claude-code')), new Response(200, ['Content-Type' => 'text/event-stream'], 'data: ' . self::INITIALIZE_RESULT . "\n\n")],
            'error status' => [static fn (): bool => true, self::post(self::initialize('claude-code')), new Response(400, $json, self::INITIALIZE_RESULT)],
            'GET' => [static fn (): bool => true, new ServerRequest('GET', 'http://localhost/mcp'), new Response(200, $json, self::INITIALIZE_RESULT)],
            'non-seekable request body' => [
                static fn (): bool => true,
                (new ServerRequest('POST', 'http://localhost/mcp'))->withBody($nonSeekable),
                new Response(200, $json, self::INITIALIZE_RESULT),
            ],
        ] as $case => [$gate, $request, $response]) {
            $result = self::middleware($gate)->process($request, self::handler($response));
            self::assertSame($response, $result, $case);
        }
    }

    private static function initialize(string $client): string
    {
        return \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => $client, 'version' => '1.0'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private static function post(string $body): ServerRequest
    {
        return new ServerRequest('POST', 'http://localhost/mcp', ['Content-Type' => 'application/json'], $body);
    }

    /**
     * @param (\Closure(): bool)|null $gate
     */
    private static function middleware(?\Closure $gate): AnalyticsHttpMiddleware
    {
        return new AnalyticsHttpMiddleware(new RequestContextStore(), $gate, new Psr17Factory());
    }

    private static function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                FeedbackInstructionsTest::$handled = $request->getBody()->getContents();

                return $this->response;
            }
        };
    }
}

/**
 * A seekable stream that reports no size, like php://input.
 */
final class UnknownSizeStream implements StreamInterface
{
    private readonly StreamInterface $inner;

    public function __construct(string $contents)
    {
        $this->inner = Stream::create($contents);
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return true;
    }

    /**
     * @param int $offset
     * @param int $whence
     */
    public function seek($offset, $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    /**
     * @param string $string
     */
    public function write($string): int
    {
        throw new \RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    /**
     * @param int $length
     */
    public function read($length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    /**
     * @param string|null $key
     */
    public function getMetadata($key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
