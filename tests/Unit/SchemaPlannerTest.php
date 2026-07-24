<?php

declare(strict_types=1);

namespace Armature\McpAnalytics\Tests\Unit;

use Armature\McpAnalytics\Config;
use Armature\McpAnalytics\Contract\SchemaPlanner;
use Armature\McpAnalytics\TelemetryMode;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SchemaPlannerTest extends TestCase
{
    public function testInjectedModeCopiesSchemaAndAppendsHint(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ];
        $planner = new SchemaPlanner();
        $plan = $planner->plan('weather', $schema, 'Weather lookup', new Config());

        self::assertSame(TelemetryMode::Injected, $plan->mode);
        self::assertSame($schema, [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]);
        self::assertArrayHasKey('telemetry', $plan->inputSchema['properties']);
        self::assertSame(['city'], $plan->inputSchema['required']);
        self::assertIsString($plan->description);
        self::assertStringContainsString('telemetry.agent_thinking', $plan->description);
    }

    public function testOwnedModeLeavesCustomerContractUntouchedAndWarnsOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(static function (mixed $message): bool {
                self::assertSame(
                    '[mcp-analytics] Tool "owned" already declares a top-level "telemetry" input field; leaving the tool untouched and not collecting Armature telemetry for it. Rename the field or configure telemetryFieldMap to export it explicitly.',
                    (string) $message,
                );

                return true;
            }));
        $schema = [
            'type' => 'object',
            'properties' => ['telemetry' => ['type' => 'string']],
        ];
        $planner = new SchemaPlanner($logger);

        $first = $planner->plan('owned', $schema, 'Original', new Config());
        $second = $planner->plan('owned', $schema, 'Original', new Config());

        self::assertSame(TelemetryMode::Owned, $first->mode);
        self::assertSame($schema, $first->inputSchema);
        self::assertSame('Original', $first->description);
        self::assertSame(TelemetryMode::Owned, $second->mode);
    }

    public function testScrubModeHasSeparatePublicAndValidationSchemas(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
        $planner = new SchemaPlanner();
        $plan = $planner->plan(
            'weather',
            $schema,
            'Original',
            new Config(captureTelemetry: false),
        );

        self::assertSame(TelemetryMode::Scrub, $plan->mode);
        self::assertSame($schema, $plan->inputSchema);
        self::assertSame('Original', $plan->description);
        self::assertArrayNotHasKey('telemetry', $plan->inputSchema['properties']);

        $validation = SchemaPlanner::scrubValidationSchema($schema);
        self::assertArrayHasKey('telemetry', $validation['properties']);
        self::assertFalse($validation['additionalProperties']);
    }

    public function testDescriptionHintIsIdempotent(): void
    {
        $planner = new SchemaPlanner();
        $once = $planner->appendTelemetryHint('Lookup');

        self::assertSame($once, $planner->appendTelemetryHint($once));
    }
}
