<?php

declare(strict_types=1);

use Armature\McpAnalytics\Analytics;
use Armature\McpAnalytics\Config;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require __DIR__ . '/vendor/autoload.php';

if (false === getenv('ANALYTICS_INGEST_API_KEY')) {
    throw new RuntimeException('Set ANALYTICS_INGEST_API_KEY before starting the server.');
}

$builder = Server::builder()->setServerInfo('armature-minimal-php', '0.1.0');
$analytics = Analytics::instrument($builder, Config::fromEnvironment());

$builder->addTool(
    static fn (string $text): string => 'echo: ' . $text,
    name: 'echo',
    description: 'Echo the supplied text.',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'text' => ['type' => 'string'],
        ],
        'required' => ['text'],
    ],
);

$server = $builder->build();

try {
    $server->run(new StdioTransport());
} finally {
    $analytics->close();
}
