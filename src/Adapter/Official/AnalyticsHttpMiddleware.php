<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Adapter\Official;

use Armature\McpAnalytics\Contract\FeedbackInstructions;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Copies the headers and request attributes attribution needs into the
 * request context. For a Claude Code client it also adds the send_feedback
 * sentence to the server instructions in the `initialize` and
 * `server/discover` responses (see FeedbackInstructions); every other
 * request and response passes through unchanged.
 */
final class AnalyticsHttpMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private const HEADERS = [
        'Authorization',
        'Mcp-Session-Id',
        'Mcp-Protocol-Version',
        'X-Armature-Workflow-Run-Id',
        'User-Agent',
    ];

    /** @var list<string> */
    private const ATTRIBUTES = [
        'principal_id',
        'principalId',
        'subject',
        'sub',
        'client_id',
        'clientId',
        'api_key',
        'apiKey',
    ];

    /**
     * @param (\Closure(): bool)|null $feedbackInstructions whether to add the
     *                                                      send_feedback sentence;
     *                                                      null never adds it
     */
    public function __construct(
        private readonly RequestContextStore $store,
        private readonly ?\Closure $feedbackInstructions = null,
        private ?StreamFactoryInterface $streamFactory = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $headers = [];
        foreach (self::HEADERS as $name) {
            $value = $request->getHeaderLine($name);
            if ('' !== $value) {
                $headers[$name] = $value;
            }
        }
        $attributes = [];
        foreach (self::ATTRIBUTES as $name) {
            $value = $request->getAttribute($name);
            if (\is_string($value) && '' !== $value) {
                $attributes[$name] = $value;
            }
        }

        $requestBody = $this->instructionsRequestBody($request);

        $response = $this->store->run(
            ['headers' => $headers, 'attributes' => $attributes],
            static fn (): ResponseInterface => $handler->handle($request),
        );

        return null === $requestBody ? $response : $this->withFeedbackInstructions($requestBody, $response);
    }

    /**
     * The body of a request that may need the send_feedback sentence, read
     * without moving the stream. Null for everything else.
     */
    private function instructionsRequestBody(ServerRequestInterface $request): ?string
    {
        if (null === $this->feedbackInstructions || 'POST' !== \strtoupper($request->getMethod())) {
            return null;
        }
        $body = $request->getBody();
        // php://input reports no size, so read at most one byte past the limit.
        $size = $body->getSize();
        if (!$body->isSeekable() || (null !== $size && $size > FeedbackInstructions::MAX_REQUEST_BYTES)) {
            return null;
        }
        $contents = self::read($body, FeedbackInstructions::MAX_REQUEST_BYTES + 1);

        return null !== $contents && FeedbackInstructions::applies($contents) ? $contents : null;
    }

    private function withFeedbackInstructions(string $requestBody, ResponseInterface $response): ResponseInterface
    {
        $applies = $this->feedbackInstructions;
        if (
            null === $applies
            || 200 !== $response->getStatusCode()
            || !\str_contains(\strtolower($response->getHeaderLine('Content-Type')), 'application/json')
            || !$applies()
        ) {
            return $response;
        }
        $body = $response->getBody();
        if (!$body->isSeekable()) {
            return $response;
        }
        $contents = self::read($body);
        $rewritten = null === $contents ? null : FeedbackInstructions::rewrite($requestBody, $contents);
        if (null === $rewritten) {
            return $response;
        }

        $this->streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        $response = $response->withBody($this->streamFactory->createStream($rewritten));

        return $response->hasHeader('Content-Length')
            ? $response->withHeader('Content-Length', (string) \strlen($rewritten))
            : $response;
    }

    private static function read(StreamInterface $stream, ?int $limit = null): ?string
    {
        try {
            $position = $stream->tell();
            $stream->rewind();
            if (null === $limit) {
                $contents = $stream->getContents();
            } else {
                $contents = '';
                while (\strlen($contents) < $limit && !$stream->eof()) {
                    $chunk = $stream->read($limit - \strlen($contents));
                    if ('' === $chunk) {
                        break;
                    }
                    $contents .= $chunk;
                }
            }
            $stream->seek($position);

            return $contents;
        } catch (\RuntimeException) {
            return null;
        }
    }
}
