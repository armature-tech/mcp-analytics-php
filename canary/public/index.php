<?php

declare(strict_types=1);

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionManagerInterface;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__) . '/vendor/autoload.php';

final class CanarySessionManager implements SessionManagerInterface
{
    private ?Uuid $nextId;

    public function __construct(
        private readonly FileSessionStore $store,
        ?string $nextId,
    ) {
        $this->nextId = is_string($nextId) && Uuid::isValid($nextId)
            ? Uuid::fromString($nextId)
            : null;
    }

    public function create(): SessionInterface
    {
        $id = $this->nextId ?? Uuid::v4();
        $this->nextId = null;

        return new Session($this->store, $id);
    }

    public function createWithId(Uuid $id): SessionInterface
    {
        return new Session($this->store, $id);
    }

    public function exists(Uuid $id): bool
    {
        return $this->store->exists($id);
    }

    public function destroy(Uuid $id): bool
    {
        return $this->store->destroy($id);
    }

    public function gc(): void
    {
        $this->store->gc();
    }
}

/**
 * This is a dedicated test-organization endpoint. Real servers keep the
 * workflow header so synthetic traffic is excluded from user-facing
 * analytics. Here the workflow UUID becomes the official MCP session UUID,
 * which keeps the session visible while preserving exact run correlation.
 */
function workflowSessionSeed(string $candidate): ?string
{
    $candidate = strtolower(trim($candidate));

    return 1 === preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        $candidate,
    ) ? $candidate : null;
}

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if (false === $value || '' === trim($value)) {
        throw new RuntimeException('Canary runtime configuration is incomplete.');
    }

    return trim($value);
}

function normalizeAppRunnerHost(
    ServerRequestInterface $request,
    string $allowedHost,
): ServerRequestInterface {
    $hostValues = array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', $request->getHeaderLine('Host')),
    )));
    $normalized = array_values(array_unique(array_map('strtolower', $hostValues)));
    $allowed = strtolower($allowedHost);

    if (
        count($hostValues) > 1
        && 1 === count($normalized)
        && ($allowed === $normalized[0] || str_starts_with($normalized[0], $allowed . ':'))
    ) {
        return $request->withHeader('Host', $hostValues[0]);
    }

    return $request;
}

try {
    $factory = new Psr17Factory();
    $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
    $workflowSeed = workflowSessionSeed($request->getHeaderLine('X-Armature-Workflow-Run-Id'));
    $request = $request->withoutHeader('X-Armature-Workflow-Run-Id');
    $deployment = requiredEnvironment('SDK_CANARY_DEPLOYMENT');
    $platformUrl = rtrim(requiredEnvironment('SDK_CANARY_PLATFORM_URL'), '/');
    $allowedHost = requiredEnvironment('SDK_CANARY_ALLOWED_HOST');
    $request = normalizeAppRunnerHost($request, $allowedHost);
    if ('1' === getenv('SDK_CANARY_LOCAL_DEBUG')) {
        error_log('PHP SDK canary request host: ' . $request->getHeaderLine('Host'));
    }
    $sessionDirectory = getenv('SDK_CANARY_SESSION_DIR');
    $sessionDirectory = false === $sessionDirectory || '' === trim($sessionDirectory)
        ? '/app/sessions'
        : trim($sessionDirectory);
    $intent = str_starts_with($deployment, 'sdk-canary/')
        ? $deployment
        : 'sdk-canary/php/' . $deployment;

    $builder = Server::builder()
        ->setServerInfo('sdk-canary-php', $deployment)
        ->setSession(
            sessionManager: new CanarySessionManager(
                new FileSessionStore($sessionDirectory, 7_200),
                $workflowSeed,
            ),
        );
    $analytics = Analytics::instrument(
        $builder,
        new Config(
            endpointUrl: $platformUrl . '/api/mcp-analytics/ingest',
            apiKey: requiredEnvironment('SDK_CANARY_INGEST_KEY'),
            timeoutMs: 10_000,
            actorId: 'sdk-canary-browser-worker',
            requestCapability: false,
        ),
    );
    $builder->addTool(
        static fn (RequestContext $context): array => [
            'package' => 'php',
            'deployment' => $deployment,
            'session_id' => $context->getSession()->getId()->toRfc4122(),
            'next_step' => 'Call canary_echo exactly once. Do not call canary_identity again.',
        ],
        name: 'canary_identity',
        description: 'Call exactly once to get this MCP session identity. Reuse the result; '
            . 'do not retry or call this tool again. Set telemetry.user_intent exactly to ' . $intent . '.',
        inputSchema: ['type' => 'object', 'properties' => []],
    );
    $builder->addTool(
        static fn (string $marker, RequestContext $context): array => [
            'marker' => $marker,
            'session_id' => $context->getSession()->getId()->toRfc4122(),
            'deployment' => $deployment,
        ],
        name: 'canary_echo',
        description: 'Call exactly once after canary_identity to echo a marker. '
            . 'Omit telemetry.user_intent because this continues the same user turn.',
        inputSchema: [
            'type' => 'object',
            'properties' => ['marker' => ['type' => 'string']],
            'required' => ['marker'],
        ],
    );
    $server = $builder->build();
    $transport = new StreamableHttpTransport(
        request: $request,
        responseFactory: $factory,
        streamFactory: $factory,
        middleware: [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware([
                $allowedHost,
                'localhost',
                '127.0.0.1',
                '[::1]',
            ]),
            new ProtocolVersionMiddleware(),
            $analytics->httpMiddleware(),
        ],
    );

    try {
        $response = $server->run($transport);
    } finally {
        $analytics->close();
    }

    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header($name . ': ' . $value, false);
        }
    }
    echo (string) $response->getBody();
} catch (Throwable $error) {
    $diagnostic = 'PHP SDK canary request failed: ' . $error::class;
    if ('1' === getenv('SDK_CANARY_LOCAL_DEBUG')) {
        $diagnostic .= ': ' . $error->getMessage();
    }
    error_log($diagnostic);
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"canary request failed"}';
}
