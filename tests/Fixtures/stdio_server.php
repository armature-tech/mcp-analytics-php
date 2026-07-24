<?php

declare(strict_types=1);

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Delivery\EmitterInterface;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class JsonLineEmitter implements EmitterInterface
{
    public function __construct(private readonly string $path)
    {
    }

    public function emit(array $batch): void
    {
        file_put_contents(
            $this->path,
            json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}

$sinkPath = getenv('ARMATURE_TEST_SINK');
if (false === $sinkPath || '' === $sinkPath) {
    throw new RuntimeException('ARMATURE_TEST_SINK is required.');
}

$builder = Server::builder()->setServerInfo('stdio-test', '1.0.0');
$analytics = Analytics::instrument(
    $builder,
    new Config(
        emitter: new JsonLineEmitter($sinkPath),
        requestCapability: false,
    ),
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
$server = $builder->build();

try {
    $server->run(new StdioTransport());
} finally {
    $analytics->close();
}
