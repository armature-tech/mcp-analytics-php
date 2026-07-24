<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class StdioE2eTest extends TestCase
{
    public function testRealOfficialStdioHandshakeListAndToolCall(): void
    {
        $sink = \tempnam(\sys_get_temp_dir(), 'armature-php-stdio-');
        self::assertNotFalse($sink);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = \proc_open(
            [PHP_BINARY, __DIR__ . '/../Fixtures/stdio_server.php'],
            $descriptorSpec,
            $pipes,
            \dirname(__DIR__, 2),
            ['ARMATURE_TEST_SINK' => $sink],
        );
        self::assertIsResource($process);
        self::assertCount(3, $pipes);

        $messages = [
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'stdio-client', 'version' => '1.2.3'],
                ],
            ],
            [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
                'params' => [],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => [],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'echo',
                    'arguments' => [
                        'text' => 'hello',
                        'telemetry' => [
                            'user_intent' => 'test the PHP SDK',
                            'agent_thinking' => 'the echo tool verifies execution',
                        ],
                    ],
                ],
            ],
        ];
        foreach ($messages as $message) {
            \fwrite($pipes[0], \json_encode($message, JSON_THROW_ON_ERROR) . PHP_EOL);
        }
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        try {
            self::assertSame(0, $exitCode, $stderr);
            $responses = self::jsonLines($stdout);
            $list = self::response($responses, 2);
            $tools = $list['result']['tools'] ?? null;
            self::assertIsArray($tools);
            self::assertSame('echo', $tools[0]['name']);
            self::assertArrayHasKey('telemetry', $tools[0]['inputSchema']['properties']);

            $call = self::response($responses, 3);
            self::assertSame('echo: hello', $call['result']['content'][0]['text']);

            $batches = self::jsonLines((string) \file_get_contents($sink));
            $events = [];
            foreach ($batches as $batch) {
                foreach ($batch['events'] as $event) {
                    $events[] = $event;
                }
            }
            $sessionEvents = \array_values(\array_filter(
                $events,
                static fn (array $event): bool => 'session_init' === $event['kind'],
            ));
            $toolEvents = \array_values(\array_filter(
                $events,
                static fn (array $event): bool => 'tool_call' === $event['kind'],
            ));
            self::assertCount(1, $sessionEvents);
            self::assertCount(1, $toolEvents);
            self::assertSame('stdio-client', $sessionEvents[0]['metadata']['client_name']);
            self::assertSame('test the PHP SDK', $toolEvents[0]['metadata']['user_intent']);
            self::assertSame($sessionEvents[0]['session_id_hint'], $toolEvents[0]['session_id_hint']);
            self::assertStringNotContainsString(
                '"telemetry"',
                $toolEvents[0]['metadata']['input_preview'],
            );
        } finally {
            \unlink($sink);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonLines(string $text): array
    {
        $result = [];
        foreach (\preg_split('/\R/', \trim($text)) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }
            $decoded = \json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $result[] = $decoded;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $responses
     *
     * @return array<string, mixed>
     */
    private static function response(array $responses, int $id): array
    {
        foreach ($responses as $response) {
            if ($id === ($response['id'] ?? null)) {
                return $response;
            }
        }

        self::fail('No response for id ' . $id);
    }
}
