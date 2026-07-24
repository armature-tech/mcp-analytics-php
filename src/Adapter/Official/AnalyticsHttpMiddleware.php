<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Adapter\Official;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

    public function __construct(private readonly RequestContextStore $store)
    {
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

        return $this->store->run(
            ['headers' => $headers, 'attributes' => $attributes],
            static fn (): ResponseInterface => $handler->handle($request),
        );
    }
}
