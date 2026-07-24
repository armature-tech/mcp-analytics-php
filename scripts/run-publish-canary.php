<?php

declare(strict_types=1);

/**
 * Build the release-shaped Composer archive and install it in a blank
 * consumer. The staging manifest adds the version metadata an artifact
 * repository needs; Packagist derives that same version from the release tag.
 */

$options = getopt('', ['artifact::', 'keep-temp', 'output-dir::', 'require-platform', 'version::']);
$packageDirectory = dirname(__DIR__);
$temporaryDirectory = sys_get_temp_dir().'/armature-php-artifact-'.bin2hex(random_bytes(8));
$stagingDirectory = $temporaryDirectory.'/package';
$artifactDirectory = $temporaryDirectory.'/dist';
$consumerDirectory = $temporaryDirectory.'/consumer';
$version = isset($options['version']) && \is_string($options['version'])
    ? $options['version']
    : '0.1.0';
if (1 !== preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    throw new InvalidArgumentException('version must use MAJOR.MINOR.PATCH.');
}
if (isset($options['require-platform'])) {
    $missing = [];
    foreach ([
        'SDK_CANARY_INGEST_KEY',
        'SDK_CANARY_READ_API_KEY',
        'SDK_CANARY_MCP_SERVER_ID',
        'SDK_CANARY_PLATFORM_URL',
    ] as $name) {
        $value = getenv($name);
        if (false === $value || '' === trim($value)) {
            $missing[] = $name;
        }
    }
    if ([] !== $missing) {
        throw new RuntimeException('Missing live canary configuration: '.implode(', ', $missing));
    }
}

try {
    mkdir($temporaryDirectory, 0700, true);
    $artifact = isset($options['artifact']) && \is_string($options['artifact'])
        ? realpath($options['artifact'])
        : false;
    if (false === $artifact) {
        copyTree($packageDirectory, $stagingDirectory, [
            '.git',
            '.phpstan-cache',
            '.phpunit.cache',
            'composer.lock',
            'dist',
            'tests',
            'vendor',
        ]);
        $manifestPath = $stagingDirectory.'/composer.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($manifest)) {
            throw new RuntimeException('Invalid staged composer.json.');
        }
        $manifest['version'] = $version;
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
        mkdir($artifactDirectory, 0700, true);
        run([
            'composer',
            'archive',
            '--working-dir='.$stagingDirectory,
            '--format=zip',
            '--dir='.$artifactDirectory,
        ]);
        $artifacts = glob($artifactDirectory.'/*.zip');
        $artifact = false === $artifacts ? false : ($artifacts[0] ?? false);
    }
    if (false === $artifact || !is_file($artifact)) {
        throw new RuntimeException('No Composer archive was produced.');
    }
    if (isset($options['output-dir']) && \is_string($options['output-dir'])) {
        $outputDirectory = $options['output-dir'];
        if (!is_dir($outputDirectory)) {
            mkdir($outputDirectory, 0700, true);
        }
        $outputArtifact = rtrim($outputDirectory, '/').'/'.basename($artifact);
        copy($artifact, $outputArtifact);
    }

    mkdir($consumerDirectory, 0700, true);
    run(
        ['composer', 'init', '--no-interaction', '--name=armature/php-artifact-consumer'],
        $consumerDirectory,
    );
    run(
        ['composer', 'config', 'repositories.release', 'artifact', dirname($artifact)],
        $consumerDirectory,
    );
    run(
        ['composer', 'require', 'armature/mcp-analytics:'.$version, '--no-interaction', '--prefer-dist'],
        $consumerDirectory,
    );
    run(
        ['composer', 'require', 'nyholm/psr7:^1.8', '--no-interaction', '--prefer-dist'],
        $consumerDirectory,
    );
    $installed = $consumerDirectory.'/vendor/armature/mcp-analytics';
    foreach (['tests', 'vendor', '.github', '.phpstan-cache'] as $forbidden) {
        if (file_exists($installed.'/'.$forbidden)) {
            throw new RuntimeException('Archive unexpectedly contains '.$forbidden.'.');
        }
    }
    foreach ([
        'README.md',
        'CHANGELOG.md',
        'LICENSE',
        'SKILL.md',
        'examples/minimal/README.md',
        'examples/minimal/composer.json',
        'examples/minimal/server.php',
    ] as $requiredFile) {
        if (!is_file($installed.'/'.$requiredFile)) {
            throw new RuntimeException('Archive is missing '.$requiredFile.'.');
        }
    }

    $consumerScript = <<<'PHP'
<?php

declare(strict_types=1);

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Delivery\EmitterInterface;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\Uid\Uuid;

require __DIR__.'/vendor/autoload.php';

final class ArtifactEmitter implements EmitterInterface
{
    public array $batches = [];

    public function emit(array $batch): void
    {
        $this->batches[] = $batch;
    }
}

$emitter = new ArtifactEmitter();
$builder = Server::builder()->setServerInfo('artifact-consumer', '1.0.0');
$analytics = Analytics::instrument(
    $builder,
    new Config(emitter: $emitter, requestCapability: false),
);
$builder->addTool(
    static fn (string $text): string => 'echo: '.$text,
    name: 'echo',
    description: 'Echo',
    inputSchema: [
        'type' => 'object',
        'properties' => ['text' => ['type' => 'string']],
        'required' => ['text'],
    ],
);
$server = $builder->build();
$reference = $analytics->registry()->getTool('echo');
$public = $analytics->registry()->getTools()->references['echo'];
if (!$public instanceof Tool || !isset($public->inputSchema['properties']['telemetry'])) {
    throw new RuntimeException('Artifact did not decorate the public schema.');
}
$session = new Session(new InMemorySessionStore(), Uuid::v4());
$result = $analytics->referenceHandler()->handle($reference, [
    'text' => 'hello',
    'telemetry' => ['user_intent' => 'test artifact'],
    '_session' => $session,
]);
if ('echo: hello' !== $result) {
    throw new RuntimeException('Artifact changed the tool result.');
}
$encoded = json_encode($emitter->batches, JSON_THROW_ON_ERROR);
if (str_contains($encoded, '"telemetry"') || !str_contains($encoded, 'test artifact')) {
    throw new RuntimeException('Artifact telemetry stripping/emission failed.');
}

$factory = new Psr17Factory();
$httpRequest = static fn (array $payload, array $headers = []): ServerRequest => new ServerRequest(
    'POST',
    'http://localhost/mcp',
    ['Content-Type' => 'application/json', ...$headers],
    json_encode($payload, JSON_THROW_ON_ERROR),
);
$initializeResponse = $server->run(new StreamableHttpTransport(
    request: $httpRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'artifact-http', 'version' => '1.0'],
        ],
    ]),
    responseFactory: $factory,
    streamFactory: $factory,
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        $analytics->httpMiddleware(),
    ],
));
$httpSessionId = $initializeResponse->getHeaderLine('Mcp-Session-Id');
if (200 !== $initializeResponse->getStatusCode() || '' === $httpSessionId) {
    throw new RuntimeException('Artifact Streamable HTTP initialize failed.');
}
$listResponse = $server->run(new StreamableHttpTransport(
    request: $httpRequest([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => [],
    ], [
        'Mcp-Session-Id' => $httpSessionId,
        'Mcp-Protocol-Version' => '2025-06-18',
    ]),
    responseFactory: $factory,
    streamFactory: $factory,
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        $analytics->httpMiddleware(),
    ],
));
$listed = json_decode((string) $listResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
if (!isset($listed['result']['tools'][0]['inputSchema']['properties']['telemetry'])) {
    throw new RuntimeException('Artifact Streamable HTTP tools/list was not decorated.');
}
$callResponse = $server->run(new StreamableHttpTransport(
    request: $httpRequest([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'echo',
            'arguments' => [
                'text' => 'http',
                'telemetry' => ['user_intent' => 'test artifact HTTP'],
            ],
        ],
    ], [
        'Mcp-Session-Id' => $httpSessionId,
        'Mcp-Protocol-Version' => '2025-06-18',
    ]),
    responseFactory: $factory,
    streamFactory: $factory,
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        $analytics->httpMiddleware(),
    ],
));
$called = json_decode((string) $callResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
if ('echo: http' !== ($called['result']['content'][0]['text'] ?? null)) {
    throw new RuntimeException('Artifact Streamable HTTP tools/call failed.');
}
$analytics->close();

$stdioEmitter = new ArtifactEmitter();
$stdioBuilder = Server::builder()->setServerInfo('artifact-stdio', '1.0.0');
$stdioAnalytics = Analytics::instrument(
    $stdioBuilder,
    new Config(emitter: $stdioEmitter, requestCapability: false),
);
$stdioBuilder->addTool(
    static fn (string $text): string => 'echo: '.$text,
    name: 'echo',
    inputSchema: [
        'type' => 'object',
        'properties' => ['text' => ['type' => 'string']],
        'required' => ['text'],
    ],
);
$stdioServer = $stdioBuilder->build();
$stdioInputPath = tempnam(sys_get_temp_dir(), 'artifact-stdio-in-');
$stdioOutputPath = tempnam(sys_get_temp_dir(), 'artifact-stdio-out-');
if (false === $stdioInputPath || false === $stdioOutputPath) {
    throw new RuntimeException('Could not allocate artifact stdio files.');
}
$stdioMessages = array_map(
    static fn (array $message): string => json_encode($message, JSON_THROW_ON_ERROR),
    [
    [
        'jsonrpc' => '2.0',
        'id' => 10,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'artifact-stdio', 'version' => '1.0'],
        ],
    ],
    [
        'jsonrpc' => '2.0',
        'id' => 11,
        'method' => 'tools/list',
        'params' => [],
    ],
    [
        'jsonrpc' => '2.0',
        'id' => 12,
        'method' => 'tools/call',
        'params' => [
            'name' => 'echo',
            'arguments' => [
                'text' => 'stdio',
                'telemetry' => ['user_intent' => 'test artifact stdio'],
            ],
        ],
    ],
],
);
file_put_contents($stdioInputPath, implode(PHP_EOL, $stdioMessages).PHP_EOL);
$stdioInput = fopen($stdioInputPath, 'r');
$stdioOutput = fopen($stdioOutputPath, 'w');
if (false === $stdioInput || false === $stdioOutput) {
    throw new RuntimeException('Could not open artifact stdio files.');
}
$stdioServer->run(new StdioTransport($stdioInput, $stdioOutput));
$stdioAnalytics->close();
$stdioText = file_get_contents($stdioOutputPath);
unlink($stdioInputPath);
unlink($stdioOutputPath);
if (!is_string($stdioText) || !str_contains($stdioText, '"id":12') || !str_contains($stdioText, 'echo: stdio')) {
    throw new RuntimeException('Artifact stdio protocol call failed.');
}
echo "artifact consumer ok\n";
PHP;
    file_put_contents($consumerDirectory.'/verify.php', $consumerScript);
    run([PHP_BINARY, 'verify.php'], $consumerDirectory);

    if (isset($options['require-platform'])) {
        $platformScript = <<<'PHP'
<?php

declare(strict_types=1);

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Recorder;
use Armature\McpAnalytics\Version;
use Symfony\Component\HttpClient\HttpClient;

require __DIR__.'/vendor/autoload.php';

$required = static function (string $name): string {
    $value = getenv($name);
    if (false === $value || '' === trim($value)) {
        throw new RuntimeException('Live canary configuration is incomplete.');
    }

    return trim($value);
};
$base = rtrim($required('SDK_CANARY_PLATFORM_URL'), '/');
$serverId = $required('SDK_CANARY_MCP_SERVER_ID');
$marker = sprintf(
    'sdk-canary/php/%s-%s-%s',
    getenv('GITHUB_RUN_ID') ?: 'manual',
    getenv('GITHUB_RUN_ATTEMPT') ?: '1',
    bin2hex(random_bytes(8)),
);
$deliveryErrors = [];
$recorder = new Recorder(new Config(
    endpointUrl: $base.'/api/mcp-analytics/ingest',
    apiKey: $required('SDK_CANARY_INGEST_KEY'),
    timeoutMs: 10_000,
    onError: static function (Throwable $error, array $_batch) use (&$deliveryErrors): void {
        $deliveryErrors[] = $error;
    },
    actorId: 'sdk-canary-shared-actor',
    requestCapability: false,
));
foreach (['session-a', 'session-b'] as $session) {
    foreach ([
        ['call' => 'call-1', 'status' => 'ok'],
        ['call' => 'call-2', 'status' => 'error'],
    ] as $step) {
        $isError = 'error' === $step['status'];
        $recorder->recordToolCall(
            name: $isError ? 'canary_expected_error' : 'canary_echo',
            arguments: ['marker' => $session.'/'.$step['call']],
            telemetry: [
                ...('call-1' === $step['call'] ? ['user_intent' => $marker] : []),
                'agent_thinking' => 'exercise the '.$step['status'].' path',
            ],
            status: $step['status'],
            result: $isError ? null : ['marker' => $session.'/'.$step['call']],
            error: $isError ? 'expected canary error' : null,
            sessionId: $marker.'/'.$session,
            clientInfo: [
                'name' => 'sdk-canary-php-direct',
                'version' => Version::current(),
                'protocolVersion' => '2025-06-18',
            ],
        );
    }
}
$recorder->close();
if ([] !== $deliveryErrors) {
    throw new RuntimeException('Platform ingest failed with '.$deliveryErrors[0]::class.'.');
}

$client = HttpClient::create([
    'headers' => [
        'Authorization' => 'Bearer '.$required('SDK_CANARY_READ_API_KEY'),
        'Accept' => 'application/json',
        'User-Agent' => 'armature-sdk-canary-php/'.Version::current(),
    ],
    'timeout' => 15,
]);
$matches = [];
$deadline = microtime(true) + 90;
do {
    $response = $client->request('GET', $base.'/api/armature/v1/insights/sessions', [
        'query' => ['range' => '24h', 'intent' => $marker, 'limit' => 100],
    ]);
    if (200 !== $response->getStatusCode()) {
        throw new RuntimeException('Session readback returned HTTP '.$response->getStatusCode().'.');
    }
    $payload = $response->toArray(false);
    $sessions = is_array($payload['sessions'] ?? null) ? $payload['sessions'] : [];
    $matches = array_values(array_filter(
        $sessions,
        static fn (mixed $session): bool => is_array($session)
            && $marker === ($session['raw_intent'] ?? null)
            && $serverId === ($session['mcp_server_id'] ?? null),
    ));
    if (2 === count($matches)) {
        break;
    }
    sleep(2);
} while (microtime(true) < $deadline);

if (2 !== count($matches)) {
    $hint = [] === $matches
        ? ' (ingest succeeded; check the canary organization session-visibility plan)'
        : '';
    throw new RuntimeException(sprintf(
        'Expected two platform sessions for %s, found %d%s.',
        $marker,
        count($matches),
        $hint,
    ));
}
$sessionKeys = [];
$actorIds = [];
foreach ($matches as $session) {
    $sessionKeys[] = $session['session_key'] ?? null;
    $actorIds[] = $session['actor_id'] ?? null;
}
if (2 !== count(array_unique($sessionKeys)) || 1 !== count(array_unique($actorIds))) {
    throw new RuntimeException('Platform merged actors or sessions incorrectly.');
}
foreach ($matches as $session) {
    if (
        2 !== ($session['event_count'] ?? null)
        || 1 !== ($session['ok_count'] ?? null)
        || 1 !== ($session['error_count'] ?? null)
    ) {
        throw new RuntimeException('Platform returned unexpected session counters.');
    }
    $sessionId = is_string($session['id'] ?? null) ? $session['id'] : '';
    $traceResponse = $client->request(
        'GET',
        $base.'/api/armature/v1/insights/sessions/'.rawurlencode($sessionId).'/trace',
    );
    if (200 !== $traceResponse->getStatusCode()) {
        throw new RuntimeException('Trace readback returned HTTP '.$traceResponse->getStatusCode().'.');
    }
    $tracePayload = $traceResponse->toArray(false);
    $expectedSessionKey = is_string($session['session_key'] ?? null)
        ? $session['session_key']
        : '';
    $traceSessionKey = is_string($tracePayload['session']['session_key'] ?? null)
        ? $tracePayload['session']['session_key']
        : '';
    if ('' === $expectedSessionKey || $expectedSessionKey !== $traceSessionKey) {
        throw new RuntimeException('Trace session identity mismatch in '.$sessionId.'.');
    }
    $label = str_ends_with($expectedSessionKey, '/session-a')
        ? 'session-a'
        : (str_ends_with($expectedSessionKey, '/session-b') ? 'session-b' : '');
    if ('' === $label) {
        throw new RuntimeException('Trace returned an unexpected session key in '.$sessionId.'.');
    }
    $other = 'session-a' === $label ? 'session-b' : 'session-a';
    $traceEvents = json_encode(
        is_array($tracePayload['events'] ?? null) ? $tracePayload['events'] : [],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    if (
        !str_contains($traceEvents, $label.'/call-1')
        || !str_contains($traceEvents, $label.'/call-2')
        || str_contains($traceEvents, $other)
    ) {
        throw new RuntimeException('Cross-session trace contamination in '.$sessionId.'.');
    }
    fwrite(STDOUT, 'platform session: '.$base.'/mcp-analytics/sessions/'.$sessionId.PHP_EOL);
}
PHP;
        file_put_contents($consumerDirectory.'/platform.php', $platformScript);
        run([PHP_BINARY, 'platform.php'], $consumerDirectory);
    }

    fwrite(STDOUT, 'Composer artifact canary passed: '.basename($artifact).PHP_EOL);
} finally {
    if (!isset($options['keep-temp']) && is_dir($temporaryDirectory)) {
        removeTree($temporaryDirectory);
    } elseif (isset($options['keep-temp'])) {
        fwrite(STDOUT, 'Kept '.$temporaryDirectory.PHP_EOL);
    }
}

/**
 * @param list<string> $command
 */
function run(array $command, ?string $directory = null): void
{
    $descriptorSpec = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $directory);
    if (!\is_resource($process)) {
        throw new RuntimeException('Could not start '.implode(' ', $command));
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (0 !== $exitCode) {
        throw new RuntimeException(
            implode(' ', $command).' failed: '.trim((string) $stdout."\n".(string) $stderr),
        );
    }
    if ('' !== trim((string) $stdout)) {
        fwrite(STDOUT, $stdout);
    }
}

/**
 * @param list<string> $excludedNames
 */
function copyTree(string $source, string $destination, array $excludedNames): void
{
    mkdir($destination, 0700, true);
    $iterator = new DirectoryIterator($source);
    foreach ($iterator as $item) {
        if ($item->isDot() || \in_array($item->getFilename(), $excludedNames, true)) {
            continue;
        }
        $target = $destination.'/'.$item->getFilename();
        if ($item->isDir()) {
            copyTree($item->getPathname(), $target, $excludedNames);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function removeTree(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}
